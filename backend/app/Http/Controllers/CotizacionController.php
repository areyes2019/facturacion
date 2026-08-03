<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Http\Requests\Cotizaciones\CotizacionPagoRequest;
use App\Http\Requests\Cotizaciones\EnviarCotizacionRequest;
use App\Http\Requests\Cotizaciones\StoreCotizacionRequest;
use App\Http\Requests\Cotizaciones\UpdateCotizacionRequest;
use App\Http\Resources\CotizacionResource;
use App\Mail\CotizacionEnviadaMail;
use App\Models\Cotizacion;
use App\Services\FacturaTotalesCalculator;
use App\Services\TwilioWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class CotizacionController extends Controller
{
    public function __construct(private readonly TwilioWhatsAppService $whatsapp) {}

    /**
     * Display a listing of the resource. Filtros por columna combinables (cliente, RFC, folio,
     * estado) más rango de fecha (ver 008-cotizaciones.md).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $cotizaciones = $request->user()->cotizaciones()
            ->with('cliente')
            ->when($request->string('cliente')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('cliente')->trim().'%';
                $query->whereHas('cliente', fn ($q) => $q->where('razon_social', 'like', $busqueda));
            })
            ->when($request->string('rfc')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('rfc')->trim().'%';
                $query->whereHas('cliente', fn ($q) => $q->where('rfc', 'like', $busqueda));
            })
            ->when($request->string('folio')->trim()->isNotEmpty(), fn ($query) => $query->where('folio', 'like', '%'.$request->string('folio')->trim().'%'))
            ->when($request->string('estado')->trim()->isNotEmpty(), fn ($query) => $query->where('estado', (string) $request->string('estado')))
            ->when($request->string('fecha_desde')->trim()->isNotEmpty(), fn ($query) => $query->whereDate('created_at', '>=', (string) $request->string('fecha_desde')))
            ->when($request->string('fecha_hasta')->trim()->isNotEmpty(), fn ($query) => $query->whereDate('created_at', '<=', (string) $request->string('fecha_hasta')))
            ->orderByDesc('id')
            ->paginate(15);

        return CotizacionResource::collection($cotizaciones);
    }

    public function store(StoreCotizacionRequest $request): CotizacionResource
    {
        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        $cotizacion = DB::transaction(function () use ($request, $datos, $calculo) {
            $siguienteFolio = ((int) Cotizacion::where('user_id', $request->user()->id)->max('folio')) + 1;

            $cotizacion = $request->user()->cotizaciones()->create([
                'cliente_id' => $datos['cliente_id'],
                'folio' => $siguienteFolio,
                'estado' => EstadoCotizacion::Borrador->value,
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                'subtotal' => $calculo['subtotal'],
                'total_descuento' => $calculo['total_descuento'],
                'total_iva_16' => $calculo['total_iva_16'],
                'total_iva_0' => $calculo['total_iva_0'],
                'total_exento' => $calculo['total_exento'],
                'total' => $calculo['total'],
            ]);

            $this->guardarLineas($cotizacion, $datos['lineas'], $calculo['lineas']);

            return $cotizacion;
        });

        return new CotizacionResource($cotizacion->load(['cliente', 'lineas.articulo']));
    }

    public function show(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        return new CotizacionResource($cotizacion->load(['cliente', 'lineas.articulo', 'pagos', 'factura']));
    }

    /**
     * Solo permitida en borrador/enviada; si estaba enviada, la regresa a borrador (ver
     * 008-cotizaciones.md, supuesto #18).
     */
    public function update(UpdateCotizacionRequest $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($cotizacion->estado->esEditable(), 422, 'Solo se puede editar una cotización en borrador o enviada.');

        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        DB::transaction(function () use ($cotizacion, $datos, $calculo) {
            $cotizacion->update([
                'cliente_id' => $datos['cliente_id'],
                'estado' => EstadoCotizacion::Borrador->value,
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                'subtotal' => $calculo['subtotal'],
                'total_descuento' => $calculo['total_descuento'],
                'total_iva_16' => $calculo['total_iva_16'],
                'total_iva_0' => $calculo['total_iva_0'],
                'total_exento' => $calculo['total_exento'],
                'total' => $calculo['total'],
            ]);

            $cotizacion->lineas()->delete();
            $this->guardarLineas($cotizacion, $datos['lineas'], $calculo['lineas']);
        });

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas.articulo']));
    }

    /**
     * Solo permitida en borrador; borrado físico (mismo criterio que Factura, ver
     * 007-facturacion.md).
     */
    public function destroy(Request $request, Cotizacion $cotizacion): Response
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($cotizacion->estado === EstadoCotizacion::Borrador, 422, 'Solo se puede eliminar una cotización en borrador.');

        $cotizacion->delete();

        return response()->noContent();
    }

    /**
     * Envía la cotización por correo o WhatsApp; dispara la transición borrador → enviada.
     */
    public function enviar(EnviarCotizacionRequest $request, Cotizacion $cotizacion): JsonResponse
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $cotizacion->load(['cliente', 'lineas.articulo']);

        if ($request->validated('canal') === 'correo') {
            $pdf = app('dompdf.wrapper')->loadView('pdf.cotizacion', ['cotizacion' => $cotizacion])->output();
            Mail::to($request->validated('destinatarios'))->send(new CotizacionEnviadaMail($cotizacion, $pdf));
        } else {
            $urlPdf = URL::temporarySignedRoute('cotizaciones.pdf-publico', now()->addMinutes(10), ['cotizacion' => $cotizacion->id]);
            $mensaje = "Cotización {$cotizacion->folio} por un total de $".number_format((float) $cotizacion->total, 2).' MXN.';
            $this->whatsapp->enviar((string) $request->validated('telefono'), $mensaje, $urlPdf);
        }

        if ($cotizacion->estado === EstadoCotizacion::Borrador) {
            $cotizacion->update(['estado' => EstadoCotizacion::Enviada->value]);
        }

        return response()->json(['enviado' => true]);
    }

    /**
     * PDF generado al vuelo, sin autenticación de sesión: exclusivo para que Twilio lo descargue
     * al enviar el WhatsApp (ver 008-cotizaciones.md, adición técnica 28). Protegido por firma
     * temporal de la URL (`signed` middleware), no por `auth:sanctum`.
     */
    public function pdfPublico(Request $request, Cotizacion $cotizacion): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $cotizacion->load(['cliente', 'lineas.articulo']);

        $pdf = app('dompdf.wrapper')->loadView('pdf.cotizacion', ['cotizacion' => $cotizacion]);

        return $pdf->stream('cotizacion-'.$cotizacion->folio.'.pdf');
    }

    public function pdf(Request $request, Cotizacion $cotizacion): Response
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $cotizacion->load(['cliente', 'lineas.articulo']);

        $pdf = app('dompdf.wrapper')->loadView('pdf.cotizacion', ['cotizacion' => $cotizacion]);

        return $pdf->stream('cotizacion-'.$cotizacion->folio.'.pdf');
    }

    /**
     * Registra un pago (anticipo, saldo o pago total); si la suma acumulada alcanza o supera el
     * total, la cotización pasa a `pagada` (ver 008-cotizaciones.md, supuesto #9).
     */
    public function pagos(CotizacionPagoRequest $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $datos = $request->validated();
        // Solo `anticipo` acepta el monto libre enviado; `saldo`/`pago_total` siempre se
        // autocalculan como el saldo pendiente, ignorando cualquier valor enviado (ver
        // 008-cotizaciones.md).
        $monto = $datos['tipo'] === 'anticipo'
            ? (float) $datos['monto']
            : max(0, (float) $cotizacion->total - $cotizacion->totalPagado());

        abort_if($monto <= 0, 422, 'No hay saldo pendiente por registrar.');

        DB::transaction(function () use ($cotizacion, $datos, $monto) {
            $cotizacion->pagos()->create([
                'tipo' => $datos['tipo'],
                'fecha_pago' => $datos['fecha_pago'],
                'monto' => $monto,
                'forma_pago' => $datos['forma_pago'],
            ]);

            if ($cotizacion->fresh()->totalPagado() >= (float) $cotizacion->total) {
                $cotizacion->update(['estado' => EstadoCotizacion::Pagada->value]);
            }
        });

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas.articulo', 'pagos']));
    }

    /**
     * Marca la cotización como entregada; solo alcanzable desde `pagada`.
     */
    public function entregar(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($cotizacion->estado === EstadoCotizacion::Pagada, 422, 'Solo se puede marcar como entregada una cotización pagada.');

        $cotizacion->update(['estado' => EstadoCotizacion::ProductoEntregado->value]);

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas.articulo', 'pagos']));
    }

    /**
     * Crea una copia nueva (folio propio, borrador, sin factura ni pagos asociados) — ver
     * 008-cotizaciones.md, supuesto #12.
     */
    public function duplicar(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $cotizacion->load('lineas');

        $copia = DB::transaction(function () use ($request, $cotizacion) {
            $siguienteFolio = ((int) Cotizacion::where('user_id', $request->user()->id)->max('folio')) + 1;

            $copia = $request->user()->cotizaciones()->create([
                'cliente_id' => $cotizacion->cliente_id,
                'folio' => $siguienteFolio,
                'estado' => EstadoCotizacion::Borrador->value,
                'descuento_global_tipo' => $cotizacion->descuento_global_tipo?->value,
                'descuento_global_valor' => $cotizacion->descuento_global_valor,
                'subtotal' => $cotizacion->subtotal,
                'total_descuento' => $cotizacion->total_descuento,
                'total_iva_16' => $cotizacion->total_iva_16,
                'total_iva_0' => $cotizacion->total_iva_0,
                'total_exento' => $cotizacion->total_exento,
                'total' => $cotizacion->total,
            ]);

            foreach ($cotizacion->lineas as $linea) {
                $copia->lineas()->create([
                    'articulo_id' => $linea->articulo_id,
                    'cantidad' => $linea->cantidad,
                    'descripcion' => $linea->descripcion,
                    'modelo' => $linea->modelo,
                    'precio_unitario' => $linea->precio_unitario,
                    'descuento_tipo' => $linea->descuento_tipo?->value,
                    'descuento_valor' => $linea->descuento_valor,
                    'tasa_iva' => $linea->tasa_iva->value,
                    'importe' => $linea->importe,
                    'iva_importe' => $linea->iva_importe,
                ]);
            }

            return $copia;
        });

        return new CotizacionResource($copia->load(['cliente', 'lineas.articulo']));
    }

    /**
     * @param  array{lineas: array<int, array<string, mixed>>, descuento_global_tipo?: ?string, descuento_global_valor?: ?float, total: float}  $datos
     * @return array{
     *     lineas: array<int, array{importe: float, iva_importe: float}>,
     *     subtotal: float,
     *     total_descuento: float,
     *     total_iva_16: float,
     *     total_iva_0: float,
     *     total_exento: float,
     *     total: float,
     * }
     */
    private function calcularYValidarTotal(array $datos): array
    {
        $calculo = FacturaTotalesCalculator::calcular(
            $datos['lineas'],
            $datos['descuento_global_tipo'] ?? null,
            $datos['descuento_global_valor'] ?? null,
        );

        if (abs($calculo['total'] - (float) $datos['total']) > 0.01) {
            throw ValidationException::withMessages([
                'total' => 'El total no coincide con el calculado a partir de las líneas.',
            ]);
        }

        return $calculo;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @param  array<int, array{importe: float, iva_importe: float}>  $lineasCalculadas
     */
    private function guardarLineas(Cotizacion $cotizacion, array $lineas, array $lineasCalculadas): void
    {
        foreach ($lineas as $i => $linea) {
            $cotizacion->lineas()->create([
                'articulo_id' => $linea['articulo_id'],
                'cantidad' => $linea['cantidad'],
                'descripcion' => $linea['descripcion'],
                'modelo' => $linea['modelo'],
                'precio_unitario' => $linea['precio_unitario'],
                'descuento_tipo' => $linea['descuento_tipo'] ?? null,
                'descuento_valor' => $linea['descuento_valor'] ?? null,
                'tasa_iva' => $linea['tasa_iva'],
                'importe' => $lineasCalculadas[$i]['importe'],
                'iva_importe' => $lineasCalculadas[$i]['iva_importe'],
            ]);
        }
    }
}

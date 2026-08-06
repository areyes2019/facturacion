<?php

namespace App\Models;

use App\Contracts\DocumentoEnviable;
use App\Enums\EstadoCotizacion;
use App\Enums\TipoDescuento;
use App\Mail\CotizacionEnviadaMail;
use Database\Factories\CotizacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

#[Fillable([
    'cliente_id',
    'folio',
    'estado',
    'descuento_global_tipo',
    'descuento_global_valor',
    'subtotal',
    'total_descuento',
    'total_iva_16',
    'total_iva_0',
    'total_exento',
    'total',
    'factura_id',
])]
class Cotizacion extends Model implements DocumentoEnviable
{
    /** @use HasFactory<CotizacionFactory> */
    use HasFactory;

    protected $table = 'cotizaciones';

    /** Vigencia de la URL firmada que Twilio usa para descargar el PDF adjunto. */
    private const MINUTOS_URL_PUBLICA = 10;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CotizacionLinea::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(CotizacionPago::class);
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * Suma acumulada de los pagos registrados (anticipo + saldo + pago total, sin distinción de
     * tipo); en cuanto alcanza o supera `total` la cotización pasa a `pagada` (ver
     * 008-cotizaciones.md, supuesto #9).
     */
    public function totalPagado(): float
    {
        return (float) $this->pagos()->sum('monto');
    }

    public function vistaPdf(): string
    {
        return 'pdf.cotizacion';
    }

    /**
     * @return array<string, mixed>
     */
    public function datosPdf(): array
    {
        $this->loadMissing(['cliente', 'lineas.articulo']);

        return ['cotizacion' => $this];
    }

    public function nombreArchivoPdf(): string
    {
        return 'cotizacion-'.$this->folio.'.pdf';
    }

    public function mailable(string $pdf): Mailable
    {
        return new CotizacionEnviadaMail($this, $pdf);
    }

    public function resumenWhatsApp(): string
    {
        return "Cotización {$this->folio} por un total de $".number_format((float) $this->total, 2).' MXN.';
    }

    public function urlPdfPublico(): string
    {
        return URL::temporarySignedRoute(
            'cotizaciones.pdf-publico',
            now()->addMinutes(self::MINUTOS_URL_PUBLICA),
            ['cotizacion' => $this->id],
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoCotizacion::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'total_iva_0' => 'decimal:2',
            'total_exento' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}

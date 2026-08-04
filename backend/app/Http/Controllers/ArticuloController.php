<?php

namespace App\Http\Controllers;

use App\Enums\ObjetoImpuesto;
use App\Http\Requests\Articulos\StoreArticuloRequest;
use App\Http\Requests\Articulos\UpdateArticuloRequest;
use App\Http\Resources\ArticuloResource;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Rules\ClaveProdServValido;
use App\Rules\ClaveUnidadValido;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArticuloController extends Controller
{
    /** Columnas compartidas entre la importación y la exportación CSV (ver 006-gestion-articulos.md). */
    private const COLUMNAS_CSV = [
        'nombre',
        'modelo',
        'clave_prod_serv',
        'clave_unidad',
        'objeto_imp',
        'precio_proveedor',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $articulos = $this->filtrarBusqueda($request->user()->articulos(), $request)
            ->with('catalogo.proveedor')
            ->orderBy('nombre')
            ->paginate(15);

        return ArticuloResource::collection($articulos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreArticuloRequest $request): ArticuloResource
    {
        $datos = $request->validated();
        $catalogo = Catalogo::findOrFail($datos['catalogo_id']);

        $cadena = PrecioArticuloCalculator::calcularCadena(
            (float) $datos['precio_proveedor'],
            (float) $catalogo->descuento,
            (float) ($datos['utilidad_porcentaje'] ?? $catalogo->utilidad_porcentaje),
        );

        $articulo = $request->user()->articulos()->create([
            ...$datos,
            'costo_con_descuento' => $cadena['costo_con_descuento'],
            'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
        ]);

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Articulo $articulo): ArticuloResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateArticuloRequest $request, Articulo $articulo): ArticuloResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $datos = $request->validated();
        $catalogo = Catalogo::findOrFail($datos['catalogo_id']);

        $cadena = PrecioArticuloCalculator::calcularCadena(
            (float) $datos['precio_proveedor'],
            (float) $catalogo->descuento,
            (float) ($datos['utilidad_porcentaje'] ?? $catalogo->utilidad_porcentaje),
        );

        $articulo->update([
            ...$datos,
            'costo_con_descuento' => $cadena['costo_con_descuento'],
            'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
        ]);

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Articulo $articulo): Response
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $articulo->delete();

        return response()->noContent();
    }

    /**
     * Importa artículos desde un CSV, todos asociados al catálogo de la ruta (y por lo tanto a
     * su proveedor). Las filas válidas se importan y las inválidas se reportan sin abortar el
     * archivo completo.
     */
    public function importarCsv(Request $request, Catalogo $catalogo): JsonResponse
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        // Catálogos del mismo proveedor (para la unicidad de nombre, ver 009-catalogos.md);
        // se calcula una sola vez fuera del bucle porque no cambia entre filas.
        $catalogosDelProveedor = Catalogo::query()->where('proveedor_id', $catalogo->proveedor_id)->pluck('id');

        $handle = fopen($request->file('archivo')->getRealPath(), 'r');
        $columnas = array_map(fn ($columna) => trim((string) $columna), fgetcsv($handle) ?: []);

        $importados = 0;
        $errores = [];
        $fila = 1;

        while (($registro = fgetcsv($handle)) !== false) {
            $fila++;

            if ($registro === [null] || $registro === false) {
                continue;
            }

            $datos = array_combine($columnas, $registro) ?: [];

            $validator = Validator::make(
                [
                    'nombre' => trim((string) ($datos['nombre'] ?? '')),
                    'modelo' => trim((string) ($datos['modelo'] ?? '')),
                    'clave_prod_serv' => trim((string) ($datos['clave_prod_serv'] ?? '')),
                    'clave_unidad' => strtoupper(trim((string) ($datos['clave_unidad'] ?? ''))),
                    'objeto_imp' => trim((string) ($datos['objeto_imp'] ?? '')),
                    'precio_proveedor' => trim((string) ($datos['precio_proveedor'] ?? '')),
                ],
                [
                    'nombre' => [
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('articulos', 'nombre')
                            ->whereIn('catalogo_id', $catalogosDelProveedor)
                            ->whereNull('deleted_at'),
                    ],
                    'modelo' => ['required', 'string', 'max:255'],
                    'clave_prod_serv' => ['required', 'string', new ClaveProdServValido],
                    'clave_unidad' => ['required', 'string', new ClaveUnidadValido],
                    'objeto_imp' => ['required', Rule::enum(ObjetoImpuesto::class)],
                    'precio_proveedor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
                ],
            );

            if ($validator->fails()) {
                $errores[] = ['fila' => $fila, 'motivo' => $validator->errors()->first()];

                continue;
            }

            $filaValidada = $validator->validated();

            $cadena = PrecioArticuloCalculator::calcularCadena(
                (float) $filaValidada['precio_proveedor'],
                (float) $catalogo->descuento,
                (float) $catalogo->utilidad_porcentaje,
            );

            $request->user()->articulos()->create([
                ...$filaValidada,
                'catalogo_id' => $catalogo->id,
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            ]);
            $importados++;
        }

        fclose($handle);

        return response()->json(['importados' => $importados, 'errores' => $errores]);
    }

    /**
     * Exporta a CSV el listado de artículos resultante de la búsqueda aplicada, con las mismas
     * columnas que espera la importación.
     */
    public function exportarCsv(Request $request): StreamedResponse
    {
        $articulos = $this->filtrarBusqueda($request->user()->articulos(), $request)
            ->orderBy('nombre')
            ->get();

        return response()->streamDownload(function () use ($articulos) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::COLUMNAS_CSV);

            foreach ($articulos as $articulo) {
                fputcsv($handle, [
                    $articulo->nombre,
                    $articulo->modelo,
                    $articulo->clave_prod_serv,
                    $articulo->clave_unidad,
                    $articulo->objeto_imp->value,
                    $articulo->precio_proveedor,
                ]);
            }

            fclose($handle);
        }, 'articulos.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function filtrarBusqueda(Builder $query, Request $request): Builder
    {
        return $query->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
            $search = '%'.$request->string('search')->trim().'%';
            $query->where(function ($query) use ($search) {
                $query->where('nombre', 'like', $search)
                    ->orWhere('modelo', 'like', $search)
                    ->orWhereHas('catalogo.proveedor', fn ($query) => $query->where('nombre_comercial', 'like', $search));
            });
        });
    }
}

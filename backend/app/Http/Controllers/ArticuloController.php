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
        'precio_unitario_sin_iva',
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
        $datos['precio_con_descuento'] = $this->calcularPrecioConDescuento((float) $datos['precio_unitario_sin_iva'], $catalogo);

        $articulo = $request->user()->articulos()->create($datos);

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
        $datos['precio_con_descuento'] = $this->calcularPrecioConDescuento((float) $datos['precio_unitario_sin_iva'], $catalogo);

        $articulo->update($datos);

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
                    'precio_unitario_sin_iva' => trim((string) ($datos['precio_unitario_sin_iva'] ?? '')),
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
                    'precio_unitario_sin_iva' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
                ],
            );

            if ($validator->fails()) {
                $errores[] = ['fila' => $fila, 'motivo' => $validator->errors()->first()];

                continue;
            }

            $filaValidada = $validator->validated();

            $request->user()->articulos()->create([
                ...$filaValidada,
                'catalogo_id' => $catalogo->id,
                'precio_con_descuento' => $this->calcularPrecioConDescuento((float) $filaValidada['precio_unitario_sin_iva'], $catalogo),
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
                    $articulo->precio_unitario_sin_iva,
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

    /**
     * precio_con_descuento = precio_unitario_sin_iva * (1 - descuento_del_catalogo / 100),
     * redondeado a 2 decimales (ver 009-catalogos.md).
     */
    private function calcularPrecioConDescuento(float $precioSinIva, Catalogo $catalogo): float
    {
        return round($precioSinIva * (1 - ((float) $catalogo->descuento) / 100), 2);
    }
}

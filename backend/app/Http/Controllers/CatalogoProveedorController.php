<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalogos\StoreCatalogoRequest;
use App\Http\Requests\Catalogos\UpdateCatalogoRequest;
use App\Http\Resources\CatalogoResource;
use App\Models\Catalogo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CatalogoProveedorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $catalogos = $request->user()->catalogos()
            ->with('proveedor')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('nombre', 'like', $search)
                        ->orWhereHas('proveedor', fn ($query) => $query->where('nombre_comercial', 'like', $search));
                });
            })
            ->orderBy('nombre')
            ->paginate(min($request->integer('per_page', 15), 100));

        return CatalogoResource::collection($catalogos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCatalogoRequest $request): CatalogoResource
    {
        $catalogo = $request->user()->catalogos()->create($request->validated());

        return new CatalogoResource($catalogo->load('proveedor'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Catalogo $catalogo): CatalogoResource
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        return new CatalogoResource($catalogo->load('proveedor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCatalogoRequest $request, Catalogo $catalogo): CatalogoResource
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $catalogo->update($request->validated());

        return new CatalogoResource($catalogo->load('proveedor'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Catalogo $catalogo): Response
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        abort_if(
            $catalogo->articulos()->exists(),
            409,
            'No se puede eliminar: el catálogo tiene artículos asociados'
        );

        $catalogo->delete();

        return response()->noContent();
    }
}

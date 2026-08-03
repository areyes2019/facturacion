<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticuloResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'catalogo_id' => $this->catalogo_id,
            'catalogo_nombre' => $this->whenLoaded('catalogo', fn () => $this->catalogo->nombre),
            'proveedor_id' => $this->whenLoaded('catalogo', fn () => $this->catalogo->proveedor_id),
            'proveedor_nombre_comercial' => $this->whenLoaded(
                'catalogo',
                fn () => $this->catalogo->relationLoaded('proveedor') ? $this->catalogo->proveedor->nombre_comercial : null
            ),
            'nombre' => $this->nombre,
            'modelo' => $this->modelo,
            'clave_prod_serv' => $this->clave_prod_serv,
            'clave_unidad' => $this->clave_unidad,
            'objeto_imp' => $this->objeto_imp->value,
            'precio_unitario_sin_iva' => (float) $this->precio_unitario_sin_iva,
            'precio_unitario_con_iva' => $this->precio_unitario_con_iva,
            'precio_con_descuento' => (float) $this->precio_con_descuento,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

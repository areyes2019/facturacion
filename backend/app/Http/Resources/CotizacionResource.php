<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotizacionResource extends JsonResource
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
            'folio' => $this->folio,
            'estado' => $this->estado->value,
            'cliente_id' => $this->cliente_id,
            'cliente_razon_social' => $this->whenLoaded('cliente', fn () => $this->cliente->razon_social),
            'cliente_rfc' => $this->whenLoaded('cliente', fn () => $this->cliente->rfc),
            'cliente_correo' => $this->whenLoaded('cliente', fn () => $this->cliente->correo_contacto),
            'cliente_telefono' => $this->whenLoaded('cliente', fn () => $this->cliente->telefono),
            'descuento_global_tipo' => $this->descuento_global_tipo?->value,
            'descuento_global_valor' => $this->descuento_global_valor !== null ? (float) $this->descuento_global_valor : null,
            'subtotal' => (float) $this->subtotal,
            'total_descuento' => (float) $this->total_descuento,
            'total_iva_16' => (float) $this->total_iva_16,
            'total_iva_0' => (float) $this->total_iva_0,
            'total_exento' => (float) $this->total_exento,
            'total' => (float) $this->total,
            'total_pagado' => (float) $this->totalPagado(),
            'saldo_pendiente' => (float) max(0, $this->total - $this->totalPagado()),
            'factura_id' => $this->factura_id,
            'factura_estado' => $this->whenLoaded('factura', fn () => $this->factura?->estado->value),
            'lineas' => CotizacionLineaResource::collection($this->whenLoaded('lineas')),
            'pagos' => CotizacionPagoResource::collection($this->whenLoaded('pagos')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

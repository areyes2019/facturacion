<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotizacionPagoResource extends JsonResource
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
            'tipo' => $this->tipo->value,
            'fecha_pago' => $this->fecha_pago->toDateString(),
            'monto' => (float) $this->monto,
            'forma_pago' => $this->forma_pago,
            'created_at' => $this->created_at,
        ];
    }
}

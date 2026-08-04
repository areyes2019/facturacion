<?php

namespace App\Http\Resources;

use App\Models\CotizacionPago;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoResource extends JsonResource
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
            'cuenta_id' => $this->cuenta_id,
            'cuenta_nombre' => $this->whenLoaded('cuenta', fn () => $this->cuenta->nombre),
            'tipo' => $this->tipo->value,
            'tipo_texto' => $this->tipo->texto(),
            'monto' => (float) $this->monto,
            // Cuánto suma o resta al saldo de la cuenta: el frontend lo usa para mostrar el monto
            // con signo y color según su efecto, sin tener que replicar la regla de cada tipo.
            'efecto_en_saldo' => $this->efectoEnSaldo(),
            'fecha' => $this->fecha->toDateString(),
            'concepto' => $this->concepto,
            'es_automatico' => $this->es_automatico,
            'transferencia_id' => $this->transferencia_id,
            'documento_origen' => $this->documentoOrigen(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Documento que originó el movimiento, en la forma mínima que el frontend necesita para
     * enlazarlo. Hoy solo existe `CotizacionPago`; futuros módulos se agregan aquí sin tocar el
     * modelo ni la migración (ver 010-tesoreria.md).
     *
     * @return array{tipo: string, etiqueta: string, ruta: string, id: int}|null
     */
    private function documentoOrigen(): ?array
    {
        $documento = $this->documentable;

        if ($documento instanceof CotizacionPago) {
            $cotizacion = $documento->cotizacion;

            return [
                'tipo' => 'cotizacion',
                'etiqueta' => 'COT-'.str_pad((string) $cotizacion->folio, 5, '0', STR_PAD_LEFT),
                'ruta' => 'cotizaciones-detalle',
                'id' => $cotizacion->id,
            ];
        }

        return null;
    }
}

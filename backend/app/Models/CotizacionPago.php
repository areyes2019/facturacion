<?php

namespace App\Models;

use App\Enums\TipoPagoCotizacion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cotizacion_id',
    'tipo',
    'fecha_pago',
    'monto',
    'forma_pago',
])]
class CotizacionPago extends Model
{
    protected $table = 'cotizacion_pagos';

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoPagoCotizacion::class,
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cotizacion_id',
    'articulo_id',
    'cantidad',
    'descripcion',
    'modelo',
    'precio_unitario',
    'descuento_tipo',
    'descuento_valor',
    'tasa_iva',
    'importe',
    'iva_importe',
])]
class CotizacionLinea extends Model
{
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'descuento_tipo' => TipoDescuento::class,
            'descuento_valor' => 'decimal:2',
            'tasa_iva' => TasaIva::class,
            'importe' => 'decimal:2',
            'iva_importe' => 'decimal:2',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\EstadoCotizacion;
use App\Enums\TipoDescuento;
use Database\Factories\CotizacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
class Cotizacion extends Model
{
    /** @use HasFactory<CotizacionFactory> */
    use HasFactory;

    protected $table = 'cotizaciones';

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

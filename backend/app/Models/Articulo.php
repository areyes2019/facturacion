<?php

namespace App\Models;

use App\Enums\ObjetoImpuesto;
use App\Services\PrecioArticuloCalculator;
use Database\Factories\ArticuloFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'catalogo_id',
    'nombre',
    'modelo',
    'clave_prod_serv',
    'clave_unidad',
    'objeto_imp',
    'precio_proveedor',
    'utilidad_porcentaje',
    'costo_con_descuento',
    'precio_unitario_sin_iva',
])]
class Articulo extends Model
{
    /** @use HasFactory<ArticuloFactory> */
    use HasFactory, SoftDeletes;

    /** Tasa general de IVA en México; no se contempla tasa 0%, exento ni IVA fronterizo (ver 006-gestion-articulos.md). */
    private const TASA_IVA_GENERAL = 1.16;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(Catalogo::class);
    }

    /**
     * Precio con IVA a la tasa general (16%), calculado solo para mostrarse; no se persiste.
     */
    protected function precioUnitarioConIva(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(((float) $this->precio_unitario_sin_iva) * self::TASA_IVA_GENERAL, 2),
        );
    }

    /**
     * Utilidad en pesos por unidad (sin IVA): precio de venta − costo con descuento. No se
     * persiste; es una resta de dos columnas (ver 011-precio-proveedor-utilidad.md).
     */
    protected function utilidad(): Attribute
    {
        return Attribute::make(
            get: fn (): float => PrecioArticuloCalculator::utilidad(
                (float) $this->precio_unitario_sin_iva,
                (float) $this->costo_con_descuento,
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objeto_imp' => ObjetoImpuesto::class,
            'precio_proveedor' => 'decimal:2',
            'utilidad_porcentaje' => 'decimal:2',
            'costo_con_descuento' => 'decimal:2',
            'precio_unitario_sin_iva' => 'decimal:2',
        ];
    }
}

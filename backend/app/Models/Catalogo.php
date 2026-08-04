<?php

namespace App\Models;

use App\Services\PrecioArticuloCalculator;
use Database\Factories\CatalogoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'proveedor_id',
    'nombre',
    'descuento',
    'utilidad_porcentaje',
])]
class Catalogo extends Model
{
    /** @use HasFactory<CatalogoFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'descuento' => 0,
        'utilidad_porcentaje' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'descuento' => 'decimal:2',
            'utilidad_porcentaje' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Recálculo (ver 011-precio-proveedor-utilidad.md): al editar el descuento o el porcentaje
        // de utilidad del catálogo, los artículos ya existentes actualizan su cadena de precios. Se
        // recorre cada artículo y se recalcula en PHP con PrecioArticuloCalculator (la misma lógica
        // que el resto de la app) en lugar de hacerlo en SQL, porque el techo a 2 decimales (CEIL)
        // no es portable entre MySQL y SQLite.
        static::updated(function (self $catalogo): void {
            $descuento = (float) $catalogo->descuento;
            $utilidad = (float) $catalogo->utilidad_porcentaje;

            // Un cambio de descuento mueve el precio de TODOS los artículos del catálogo, incluidos
            // los que tienen porcentaje propio, porque cambia el costo del que parten.
            if ($catalogo->wasChanged('descuento')) {
                foreach ($catalogo->articulos()->get() as $articulo) {
                    $utilidadEfectiva = (float) ($articulo->utilidad_porcentaje ?? $utilidad);
                    $articulo->update(PrecioArticuloCalculator::calcularCadena(
                        (float) $articulo->precio_proveedor,
                        $descuento,
                        $utilidadEfectiva,
                    ));
                }
            }

            // Un cambio de utilidad_porcentaje mueve el precio SOLO de los artículos que heredan el
            // porcentaje (los que tienen utilidad_porcentaje en NULL); los que tienen porcentaje
            // propio conservan su precio.
            if ($catalogo->wasChanged('utilidad_porcentaje')) {
                foreach ($catalogo->articulos()->whereNull('utilidad_porcentaje')->get() as $articulo) {
                    $articulo->update([
                        'precio_unitario_sin_iva' => PrecioArticuloCalculator::precioVentaSinIva(
                            (float) $articulo->costo_con_descuento,
                            $utilidad,
                        ),
                    ]);
                }
            }
        });
    }
}

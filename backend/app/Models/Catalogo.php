<?php

namespace App\Models;

use Database\Factories\CatalogoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'proveedor_id',
    'nombre',
    'descuento',
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
        ];
    }

    protected static function booted(): void
    {
        // Recálculo en bloque (ver 009-catalogos.md): al editar el descuento, todos los artículos
        // ya existentes del catálogo actualizan su precio_con_descuento en una sola query, sin
        // recorrerlos uno por uno con Eloquent. $descuento viene del cast decimal del propio
        // modelo (numérico garantizado), no de entrada de usuario sin validar, así que interpolarlo
        // en el SQL crudo es seguro.
        static::updated(function (self $catalogo): void {
            if (! $catalogo->wasChanged('descuento')) {
                return;
            }

            $descuento = (float) $catalogo->descuento;

            // "100.0" (no "100"): en SQLite el operador "/" entre dos literales enteros trunca a
            // división entera (ej. 20/100 = 0), a diferencia de MySQL que siempre divide en punto
            // flotante; forzar un literal decimal evita ese truncamiento en ambos motores.
            $catalogo->articulos()->update([
                'precio_con_descuento' => DB::raw("ROUND(precio_unitario_sin_iva * (1 - {$descuento} / 100.0), 2)"),
            ]);
        });
    }
}

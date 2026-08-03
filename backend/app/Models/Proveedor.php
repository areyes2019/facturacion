<?php

namespace App\Models;

use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'nombre_comercial',
    'nombre_contacto',
    'correo',
    'telefono',
    'rfc',
    'tiene_ordenes_activas',
])]
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'proveedores';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tiene_ordenes_activas' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogos(): HasMany
    {
        return $this->hasMany(Catalogo::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tiene_ordenes_activas' => 'boolean',
        ];
    }
}

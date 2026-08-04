<?php

namespace Database\Factories;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Articulo>
 */
class ArticuloFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // La cadena de precios asume el 0% de descuento y 0% de utilidad por defecto del catálogo
        // del factory (CatalogoFactory), así que costo_con_descuento y precio_unitario_sin_iva
        // coinciden con precio_proveedor. Si un test asocia el artículo a un catálogo con otro
        // descuento o utilidad debe sobreescribir estos campos explícitamente (ver 011).
        $precio = $this->faker->randomFloat(2, 10, 5000);

        return [
            'user_id' => User::factory(),
            'catalogo_id' => Catalogo::factory(),
            'nombre' => $this->faker->unique()->words(3, true),
            'modelo' => $this->faker->bothify('MOD-####'),
            'clave_prod_serv' => '43211503',
            'clave_unidad' => 'H87',
            'objeto_imp' => ObjetoImpuesto::SiObjeto,
            'precio_proveedor' => $precio,
            'utilidad_porcentaje' => null,
            'costo_con_descuento' => $precio,
            'precio_unitario_sin_iva' => $precio,
        ];
    }
}

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
        // precio_con_descuento asume el 0% de descuento por defecto del catálogo del factory
        // (CatalogoFactory); si un test asocia el artículo a un catálogo con otro descuento debe
        // sobreescribir este campo explícitamente.
        $precio = $this->faker->randomFloat(2, 10, 5000);

        return [
            'user_id' => User::factory(),
            'catalogo_id' => Catalogo::factory(),
            'nombre' => $this->faker->unique()->words(3, true),
            'modelo' => $this->faker->bothify('MOD-####'),
            'clave_prod_serv' => '43211503',
            'clave_unidad' => 'H87',
            'objeto_imp' => ObjetoImpuesto::SiObjeto,
            'precio_unitario_sin_iva' => $precio,
            'precio_con_descuento' => $precio,
        ];
    }
}

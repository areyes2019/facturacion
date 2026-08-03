<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PhpCfdi\Rfc\RfcFaker;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rfc' => (new RfcFaker)->mexicanRfcMoral(),
            'razon_social' => $this->faker->company(),
            'regimen_fiscal' => '601',
            'codigo_postal_fiscal' => '20000',
            'nombre_comercial' => $this->faker->optional()->company(),
            'correo_contacto' => $this->faker->optional()->companyEmail(),
            'telefono' => $this->faker->optional()->numerify('##########'),
            'direccion_comercial' => $this->faker->optional()->streetAddress(),
        ];
    }

    public function personaFisica(): static
    {
        return $this->state(fn () => [
            'rfc' => (new RfcFaker)->mexicanRfcFisica(),
            'regimen_fiscal' => '612',
        ]);
    }
}

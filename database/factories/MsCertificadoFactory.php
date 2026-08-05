<?php

namespace Database\Factories;

use App\Models\MsCertificado;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MsCertificado>
 */
class MsCertificadoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'no' => fake()->unique()->numerify('CERT-######'),
            'marca' => fake()->randomElement(['Toyota', 'Ford', 'Chevrolet', 'Honda']),
            'modelo' => fake()->bothify('Modelo-###??'),
            'tipo' => fake()->randomElement(['Sedán', 'SUV', 'Camión', 'Motocicleta']),
            'fabricacion' => fake()->randomElement(['Nacional', 'Importado']),
            'anio' => fake()->numberBetween(1990, (int) date('Y')),
            'niv' => fake()->unique()->bothify('?????????########'),
            'codigo' => fake()->unique()->bothify('COD-####-????'),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'first_value' => fake()->optional()->word(),
            'second_value' => fake()->optional()->word(),
            'niv' => fake()->optional()->bothify('?????????????????'),
            'year' => fake()->numberBetween(2000, 2030),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ProductReturn;
use App\Models\User;
use App\ReturnStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReturn>
 */
class ProductReturnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('RET-####'),
            'return_date' => null,
            'created_by' => User::factory(),
            'status' => ReturnStatus::Draft,
            'finalized_at' => null,
        ];
    }
}

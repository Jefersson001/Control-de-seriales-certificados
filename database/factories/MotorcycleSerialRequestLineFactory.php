<?php

namespace Database\Factories;

use App\Models\MotorcycleSerialRequestLine;
use App\Models\MotorcycleSerialRequest;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotorcycleSerialRequestLine>
 */
class MotorcycleSerialRequestLineFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (MotorcycleSerialRequestLine $line): void {
            $line->serialEntries()->createMany([
                ['serial' => strtoupper(fake()->unique()->bothify('#################'))],
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'motorcycle_serial_request_id' => MotorcycleSerialRequest::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}

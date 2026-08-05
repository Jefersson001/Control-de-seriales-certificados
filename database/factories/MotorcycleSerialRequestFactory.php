<?php

namespace Database\Factories;

use App\Models\MotorcycleSerialRequest;
use App\Models\Product;
use App\Models\User;
use App\MotorcycleSerialRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotorcycleSerialRequest>
 */
class MotorcycleSerialRequestFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (MotorcycleSerialRequest $request): void {
            $line = $request->lines()->create([
                'product_id' => Product::factory()->create()->id,
                'quantity' => fake()->numberBetween(1, 100),
            ]);
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
            'user_id' => User::factory(),
            'status' => fake()->randomElement(MotorcycleSerialRequestStatus::cases()),
        ];
    }
}

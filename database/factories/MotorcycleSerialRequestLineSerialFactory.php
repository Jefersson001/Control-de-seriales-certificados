<?php

namespace Database\Factories;

use App\Models\MotorcycleSerialRequestLineSerial;
use App\Models\MotorcycleSerialRequestLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotorcycleSerialRequestLineSerial>
 */
class MotorcycleSerialRequestLineSerialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'motorcycle_serial_request_line_id' => MotorcycleSerialRequestLine::factory(),
            'serial' => strtoupper(fake()->unique()->bothify('#################')),
        ];
    }
}

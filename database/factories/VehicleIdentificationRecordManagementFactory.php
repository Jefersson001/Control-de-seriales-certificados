<?php

namespace Database\Factories;

use App\Models\VehicleIdentificationRecordManagement;
use App\Models\MotorcycleSerialRequest;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleIdentificationRecordManagement>
 */
class VehicleIdentificationRecordManagementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'motorcycle_serial_request_id' => MotorcycleSerialRequest::factory(),
            'status' => VehicleIdentificationRecordManagementStatus::Draft,
        ];
    }
}

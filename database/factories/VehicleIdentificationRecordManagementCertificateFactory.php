<?php

namespace Database\Factories;

use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\Models\VehicleIdentificationRecordManagement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleIdentificationRecordManagementCertificate>
 */
class VehicleIdentificationRecordManagementCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'management_id' => VehicleIdentificationRecordManagement::factory(),
            'control_number' => 'DG-NIV-RG5-'.$this->faker->unique()->numerify('####').'-PC',
            'original_file_name' => $this->faker->word().'.pdf',
            'file_path' => 'vehicle-identification-record-management/'.$this->faker->uuid().'.pdf',
            'file_hash' => $this->faker->unique()->sha256(),
            'valid_occurrence_count' => 0,
            'invalid_count' => 0,
            'analyzed_at' => now(),
        ];
    }
}

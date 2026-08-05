<?php

namespace Database\Factories;

use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleIdentificationRecordCertificateSerial>
 */
class VehicleIdentificationRecordCertificateSerialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'certificate_id' => VehicleIdentificationRecordManagementCertificate::factory(),
            'request_serial_id' => null,
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::Unexpected,
            'serial' => strtoupper($this->faker->bothify('8YZ??????????????')),
            'occurrences' => 1,
            'reason' => 'El serial no pertenece a la solicitud relacionada.',
        ];
    }
}

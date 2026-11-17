<?php

namespace Database\Factories;

use App\Models\NivComplianceCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NivComplianceCertificate>
 */
class NivComplianceCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => 'nacional',
            'certificate_number' => fake()->unique()->numerify('#####'),
            'brand' => 'ZONTES',
            'model' => '150 X',
            'series_version' => '150',
            'serial_pattern' => 'LD3THK5BXV1000001',
            'original_file_name' => 'constancia.pdf',
            'file_path' => 'niv-compliance-certificates/'.fake()->uuid().'.pdf',
            'file_hash' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}

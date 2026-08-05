<?php

namespace App\Models;

use App\VehicleIdentificationRecordCertificateSerialClassification;
use Database\Factories\VehicleIdentificationRecordCertificateSerialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['certificate_id', 'request_serial_id', 'classification', 'serial', 'occurrences', 'reason', 'source_data', 'imported_at'])]
class VehicleIdentificationRecordCertificateSerial extends Model
{
    /** @use HasFactory<VehicleIdentificationRecordCertificateSerialFactory> */
    use HasFactory;

    /** @return BelongsTo<VehicleIdentificationRecordManagementCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(VehicleIdentificationRecordManagementCertificate::class, 'certificate_id');
    }

    /** @return BelongsTo<MotorcycleSerialRequestLineSerial, $this> */
    public function requestSerial(): BelongsTo
    {
        return $this->belongsTo(MotorcycleSerialRequestLineSerial::class, 'request_serial_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::class,
            'occurrences' => 'integer',
            'source_data' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}

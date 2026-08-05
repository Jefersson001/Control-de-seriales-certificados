<?php

namespace App\Models;

use Database\Factories\VehicleIdentificationRecordManagementCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'management_id',
    'control_number',
    'original_file_name',
    'file_path',
    'file_hash',
    'valid_occurrence_count',
    'invalid_count',
    'analyzed_at',
])]
class VehicleIdentificationRecordManagementCertificate extends Model
{
    /** @use HasFactory<VehicleIdentificationRecordManagementCertificateFactory> */
    use HasFactory;

    /** @return BelongsTo<VehicleIdentificationRecordManagement, $this> */
    public function management(): BelongsTo
    {
        return $this->belongsTo(VehicleIdentificationRecordManagement::class);
    }

    /** @return HasMany<VehicleIdentificationRecordCertificateSerial, $this> */
    public function serialResults(): HasMany
    {
        return $this->hasMany(VehicleIdentificationRecordCertificateSerial::class, 'certificate_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_occurrence_count' => 'integer',
            'invalid_count' => 'integer',
            'analyzed_at' => 'datetime',
        ];
    }
}

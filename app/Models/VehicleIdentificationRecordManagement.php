<?php

namespace App\Models;

use App\VehicleIdentificationRecordManagementStatus;
use Database\Factories\VehicleIdentificationRecordManagementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['motorcycle_serial_request_id', 'status'])]
class VehicleIdentificationRecordManagement extends Model
{
    /** @use HasFactory<VehicleIdentificationRecordManagementFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (VehicleIdentificationRecordManagement $management): void {
            $management->certificateDocuments()->detach();
        });
    }

    protected $table = 'vehicle_identification_record_management';

    protected $attributes = [
        'status' => VehicleIdentificationRecordManagementStatus::Draft->value,
    ];

    /** @return BelongsTo<MotorcycleSerialRequest, $this> */
    public function motorcycleSerialRequest(): BelongsTo
    {
        return $this->belongsTo(MotorcycleSerialRequest::class);
    }

    /** @return HasMany<VehicleIdentificationRecordManagementCertificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(VehicleIdentificationRecordManagementCertificate::class, 'management_id');
    }

    /** @return BelongsToMany<CertificateDocument, $this> */
    public function certificateDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            CertificateDocument::class,
            'certificate_document_management',
            'management_id',
            'certificate_document_id',
        )->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VehicleIdentificationRecordManagementStatus::class,
        ];
    }
}

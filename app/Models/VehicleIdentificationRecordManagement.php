<?php

namespace App\Models;

use App\VehicleIdentificationRecordManagementStatus;
use Database\Factories\VehicleIdentificationRecordManagementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['motorcycle_serial_request_id', 'status'])]
class VehicleIdentificationRecordManagement extends Model
{
    /** @use HasFactory<VehicleIdentificationRecordManagementFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VehicleIdentificationRecordManagementStatus::class,
        ];
    }
}

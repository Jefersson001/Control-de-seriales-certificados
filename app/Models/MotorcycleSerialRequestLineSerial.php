<?php

namespace App\Models;

use Database\Factories\MotorcycleSerialRequestLineSerialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['motorcycle_serial_request_line_id', 'serial'])]
class MotorcycleSerialRequestLineSerial extends Model
{
    /** @use HasFactory<MotorcycleSerialRequestLineSerialFactory> */
    use HasFactory;

    /** @return BelongsTo<MotorcycleSerialRequestLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(MotorcycleSerialRequestLine::class, 'motorcycle_serial_request_line_id');
    }

    /** @return HasOne<VehicleIdentificationRecordCertificateSerial, $this> */
    public function certification(): HasOne
    {
        return $this->hasOne(VehicleIdentificationRecordCertificateSerial::class, 'request_serial_id');
    }
}

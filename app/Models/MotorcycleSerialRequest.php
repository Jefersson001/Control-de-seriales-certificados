<?php

namespace App\Models;

use App\MotorcycleSerialRequestStatus;
use Database\Factories\MotorcycleSerialRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property Carbon|null $request_date
 * @property MotorcycleSerialRequestStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MotorcycleSerialRequestLine> $lines
 */
#[Fillable(['user_id', 'request_date', 'status'])]
class MotorcycleSerialRequest extends Model
{
    /** @use HasFactory<MotorcycleSerialRequestFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => MotorcycleSerialRequestStatus::Draft->value,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MotorcycleSerialRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(MotorcycleSerialRequestLine::class);
    }

    /** @return HasOne<VehicleIdentificationRecordManagement, $this> */
    public function vehicleIdentificationRecordManagement(): HasOne
    {
        return $this->hasOne(VehicleIdentificationRecordManagement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'status' => MotorcycleSerialRequestStatus::class,
        ];
    }
}

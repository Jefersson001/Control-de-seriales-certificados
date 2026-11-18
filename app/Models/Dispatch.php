<?php

namespace App\Models;

use App\DispatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property DispatchStatus $status
 * @property Carbon|null $dispatch_date
 * @property Carbon|null $finalized_at
 */
#[Fillable(['name', 'dispatch_date', 'created_by', 'status', 'finalized_at'])]
class Dispatch extends Model
{
    protected $attributes = ['status' => DispatchStatus::Draft->value];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DispatchLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class);
    }

    /** @return HasMany<DispatchUncertifiedLine, $this> */
    public function uncertifiedLines(): HasMany
    {
        return $this->hasMany(DispatchUncertifiedLine::class);
    }

    protected function casts(): array
    {
        return [
            'dispatch_date' => 'date',
            'finalized_at' => 'datetime',
            'status' => DispatchStatus::class,
        ];
    }
}

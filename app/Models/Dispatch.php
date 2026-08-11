<?php

namespace App\Models;

use App\DispatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'dispatch_date', 'created_by', 'status', 'finalized_at'])]
class Dispatch extends Model
{
    protected $attributes = ['status' => DispatchStatus::Draft->value];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class);
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

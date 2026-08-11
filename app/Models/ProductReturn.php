<?php

namespace App\Models;

use App\ReturnStatus;
use Database\Factories\ProductReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon|null $return_date
 * @property int|null $created_by
 * @property ReturnStatus $status
 * @property Carbon|null $finalized_at
 */
#[Fillable(['name', 'return_date', 'created_by', 'status', 'finalized_at'])]
class ProductReturn extends Model
{
    /** @use HasFactory<ProductReturnFactory> */
    use HasFactory;

    protected $table = 'returns';

    protected $attributes = ['status' => ReturnStatus::Draft->value];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProductReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductReturnLine::class, 'return_id');
    }

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'finalized_at' => 'datetime',
            'status' => ReturnStatus::class,
        ];
    }
}

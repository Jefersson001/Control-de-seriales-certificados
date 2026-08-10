<?php

namespace App\Models;

use Database\Factories\MotorcycleSerialRequestLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['motorcycle_serial_request_id', 'product_id', 'quantity'])]
class MotorcycleSerialRequestLine extends Model
{
    /** @use HasFactory<MotorcycleSerialRequestLineFactory> */
    use HasFactory;

    /** @return BelongsTo<MotorcycleSerialRequest, $this> */
    public function motorcycleSerialRequest(): BelongsTo
    {
        return $this->belongsTo(MotorcycleSerialRequest::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<MotorcycleSerialRequestLineSerial, $this> */
    public function serialEntries(): HasMany
    {
        return $this->hasMany(MotorcycleSerialRequestLineSerial::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}

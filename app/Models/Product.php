<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $category
 * @property string $brand
 * @property string|null $first_value
 * @property string|null $second_value
 * @property string|null $niv
 * @property int|null $year
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'category', 'brand', 'first_value', 'second_value', 'niv', 'year'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'nacional',
        'brand' => 'bera',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    /** @return HasMany<MotorcycleSerialRequestLine, $this> */
    public function motorcycleSerialRequestLines(): HasMany
    {
        return $this->hasMany(MotorcycleSerialRequestLine::class);
    }

    /** @return HasMany<NivComplianceCertificate, $this> */
    public function nivComplianceCertificates(): HasMany
    {
        return $this->hasMany(NivComplianceCertificate::class);
    }
}

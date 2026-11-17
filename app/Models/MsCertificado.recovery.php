<?php

namespace App\Models;

use App\CertificateStatus;
use Database\Factories\MsCertificadoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $no
 * @property string $marca
 * @property string $modelo
 * @property string $tipo
 * @property string $fabricacion
 * @property int $anio
 * @property string $niv
 * @property string $category
 * @property CertificateStatus $status
 * @property string $codigo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['no', 'marca', 'modelo', 'tipo', 'fabricacion', 'anio', 'niv', 'category', 'status', 'codigo', 'issued_on'])]
class MsCertificado extends Model
{
    /** @use HasFactory<MsCertificadoFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => CertificateStatus::PendingDispatch->value,
        'category' => 'nacional',
    ];

    /** @return HasMany<CertificateDocument, $this> */
    public function certificateDocuments(): HasMany
    {
        return $this->hasMany(CertificateDocument::class, 'control_number', 'codigo');
    }

    /** @param Builder<MsCertificado> $query
     * @return Builder<MsCertificado>
     */
    public function scopeFilterByCategory(Builder $query, ?string $category): Builder
    {
        return $query->when(in_array($category, ['nacional', 'importados'], true), fn (Builder $query) => $query->where('category', $category));
    }

    /**
     * @param  Builder<MsCertificado>  $query
     * @return Builder<MsCertificado>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim($search ?? '');

        return $query->when($search !== '', function (Builder $query) use ($search): void {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('no', 'like', "%{$search}%")
                    ->orWhere('marca', 'like', "%{$search}%")
                    ->orWhere('modelo', 'like', "%{$search}%")
                    ->orWhere('tipo', 'like', "%{$search}%")
                    ->orWhere('fabricacion', 'like', "%{$search}%")
                    ->orWhere('anio', 'like', "%{$search}%")
                    ->orWhere('niv', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%");
            });
        });
    }

    /**
     * @param  Builder<MsCertificado>  $query
     * @return Builder<MsCertificado>
     */
    public function scopeFilterByNivStatus(Builder $query, ?string $filter): Builder
    {
        return $query
            ->when($filter === 'duplicates', function (Builder $query): void {
                $query->whereIn(
                    'niv',
                    self::query()
                        ->select('niv')
                        ->groupBy('niv')
                        ->havingRaw('COUNT(*) >= 2'),
                );
            })
            ->when($filter === 'unique_niv', function (Builder $query): void {
                $query->whereIn(
                    'niv',
                    self::query()
                        ->select('niv')
                        ->groupBy('niv')
                        ->havingRaw('COUNT(*) = 1'),
                );
            })
            ->when($filter === 'invalid_niv_length', function (Builder $query): void {
                $query->whereRaw('LENGTH(TRIM(niv)) <> 17');
            })
            ->when($filter === 'invalid_records', function (Builder $query): void {
                $usesSqlite = $query->getConnection() instanceof SQLiteConnection;
                $numberValidation = $usesSqlite ? '"no" GLOB ?' : '`no` REGEXP ?';
                $manufacturingValidation = $usesSqlite ? '"fabricacion" GLOB ?' : '`fabricacion` REGEXP ?';
                $nivValidation = $usesSqlite ? 'UPPER(niv) GLOB ?' : 'UPPER(niv) REGEXP ?';
                $numericPattern = $usesSqlite ? '*[^0-9]*' : '[^0-9]';
                $nivPattern = $usesSqlite ? '*[^A-HJ-NPR-Z0-9]*' : '[^A-HJ-NPR-Z0-9]';

                $query->where(function (Builder $query) use ($numberValidation, $manufacturingValidation, $nivValidation, $numericPattern, $nivPattern): void {
                    $query
                        ->where('no', '')
                        ->orWhereRaw($numberValidation, [$numericPattern])
                        ->orWhere('marca', '')
                        ->orWhere('modelo', '')
                        ->orWhere('tipo', '')
                        ->orWhere('fabricacion', '')
                        ->orWhereRaw('LENGTH(TRIM(fabricacion)) <> 4')
                        ->orWhereRaw($manufacturingValidation, [$numericPattern])
                        ->orWhere('anio', '<', 1000)
                        ->orWhere('anio', '>', 9999)
                        ->orWhere('niv', '')
                        ->orWhereRaw('LENGTH(TRIM(niv)) <> 17')
                        ->orWhereRaw($nivValidation, [$nivPattern])
                        ->orWhere('codigo', '');
                });
            });
    }

    /**
     * @param  Builder<MsCertificado>  $query
     * @return Builder<MsCertificado>
     */
    public function scopeFilterByCertificateStatus(Builder $query, ?string $status): Builder
    {
        return $query->when(
            CertificateStatus::tryFrom($status ?? '') !== null,
            fn (Builder $query): Builder => $query->where('status', $status),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'issued_on' => 'date',
            'status' => CertificateStatus::class,
        ];
    }
}

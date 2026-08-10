<?php

namespace App\Models;

use App\CertificateStatus;
use Database\Factories\MsCertificadoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property CertificateStatus $status
 * @property string $codigo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['no', 'marca', 'modelo', 'tipo', 'fabricacion', 'anio', 'niv', 'status', 'codigo'])]
class MsCertificado extends Model
{
    /** @use HasFactory<MsCertificadoFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => CertificateStatus::PendingDispatch->value,
    ];

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
                $query->where(function (Builder $query): void {
                    $query
                        ->where('no', '')
                        ->orWhereRaw("no GLOB '*[^0-9]*'")
                        ->orWhere('marca', '')
                        ->orWhere('modelo', '')
                        ->orWhere('tipo', '')
                        ->orWhere('fabricacion', '')
                        ->orWhereRaw('LENGTH(TRIM(fabricacion)) <> 4')
                        ->orWhereRaw("fabricacion GLOB '*[^0-9]*'")
                        ->orWhere('anio', '<', 1000)
                        ->orWhere('anio', '>', 9999)
                        ->orWhere('niv', '')
                        ->orWhereRaw('LENGTH(TRIM(niv)) <> 17')
                        ->orWhereRaw("UPPER(niv) GLOB '*[^A-HJ-NPR-Z0-9]*'")
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
            'status' => CertificateStatus::class,
        ];
    }
}

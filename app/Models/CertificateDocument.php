<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property CarbonInterface|null $issued_on
 * @property-read int $processed_serial_count
 * @property-read int $dispatched_serial_count
 * @property-read int $pending_dispatch_serial_count
 * @property-read int $returned_serial_count
 */
#[Fillable([
    'uploaded_by',
    'imported_without_management',
    'control_number',
    'issued_on',
    'file_name',
    'original_file_name',
    'file_path',
    'total_serial_count',
])]
class CertificateDocument extends Model
{
    /**
     * @param  Builder<CertificateDocument>  $query
     * @return Builder<CertificateDocument>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim($search ?? '');

        return $query->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
            $query->where('control_number', 'like', "%{$search}%")
                ->orWhere('file_name', 'like', "%{$search}%");
        }));
    }

    protected static function booted(): void
    {
        static::deleted(function (CertificateDocument $document): void {
            Storage::disk('local')->delete($document->file_path);
        });
    }

    /** @return BelongsToMany<VehicleIdentificationRecordManagement, $this> */
    public function managements(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleIdentificationRecordManagement::class,
            'certificate_document_management',
            'certificate_document_id',
            'management_id',
        )->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'imported_without_management' => 'boolean',
            'total_serial_count' => 'integer',
        ];
    }
}

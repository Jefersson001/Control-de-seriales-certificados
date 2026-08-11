<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'uploaded_by',
    'imported_without_management',
    'control_number',
    'file_name',
    'original_file_name',
    'file_path',
])]
class CertificateDocument extends Model
{
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
            'imported_without_management' => 'boolean',
        ];
    }
}

<?php

namespace App\Actions\VehicleIdentificationRecords;

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagement;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HydrateManagementCertificateSourceData
{
    public function __construct(private ImportCertificatesFromPdf $extractor) {}

    public function handle(VehicleIdentificationRecordManagement $management): void
    {
        $certificates = $management->certificates()
            ->whereHas('serialResults', fn ($query) => $query->whereNull('source_data'))
            ->with(['serialResults' => fn ($query) => $query->whereNull('source_data')->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($certificates as $certificate) {
            if (! Storage::disk('local')->exists($certificate->file_path)) {
                continue;
            }

            $analysis = $this->extractor->parseForComparison(
                Storage::disk('local')->path($certificate->file_path),
            );
            $recordsBySerial = collect($analysis['records'])
                ->groupBy(fn (array $record): string => Str::upper(trim($record['niv'])));
            $invalidRows = collect($analysis['invalidRows']);
            $updates = [];

            foreach ($certificate->serialResults as $result) {
                $sourceData = $result->classification === VehicleIdentificationRecordCertificateSerialClassification::Invalid
                    ? $invalidRows->shift()
                    : $recordsBySerial->get(Str::upper(trim($result->serial ?? '')))?->first();

                if (! is_array($sourceData)) {
                    continue;
                }

                $updates[] = [
                    'id' => $result->id,
                    'certificate_id' => $result->certificate_id,
                    'request_serial_id' => $result->request_serial_id,
                    'classification' => $result->classification->value,
                    'serial' => $result->serial,
                    'occurrences' => $result->occurrences,
                    'reason' => $result->reason,
                    'source_data' => json_encode([
                        ...$sourceData,
                        '_requested' => $result->classification !== VehicleIdentificationRecordCertificateSerialClassification::Unexpected
                            && ! str_contains($result->reason ?? '', 'no solicitado'),
                    ], JSON_THROW_ON_ERROR),
                    'imported_at' => $result->imported_at,
                    'created_at' => $result->created_at,
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($updates, 200) as $updateChunk) {
                VehicleIdentificationRecordCertificateSerial::query()->upsert(
                    $updateChunk,
                    ['id'],
                    ['source_data', 'updated_at'],
                );
            }
        }
    }
}

<?php

namespace App\Actions\VehicleIdentificationRecords;

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Models\VehicleIdentificationRecordManagement;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessManagementCertificates
{
    public function __construct(private ImportCertificatesFromPdf $extractor) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function handle(VehicleIdentificationRecordManagement $management, array $files): array
    {
        $management->loadMissing('motorcycleSerialRequest.lines.serialEntries');
        $expectedSerials = $management->motorcycleSerialRequest->lines
            ->flatMap->serialEntries
            ->keyBy(fn ($serial): string => Str::upper(trim($serial->serial)));
        $assignedSerialIds = $management->certificates()
            ->with('serialResults:id,certificate_id,request_serial_id,classification')
            ->get()
            ->flatMap->serialResults
            ->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Certified)
            ->pluck('request_serial_id')
            ->filter()
            ->flip();
        $existingHashes = $management->certificates()->pluck('file_hash')->flip();
        $preparedFiles = [];
        $batchHashes = collect();
        foreach ($files as $file) {
            $hash = hash_file('sha256', $file->getRealPath());

            if ($existingHashes->has($hash) || $batchHashes->has($hash)) {
                throw new RuntimeException("El archivo {$file->getClientOriginalName()} ya fue procesado en esta gestión.");
            }

            $batchHashes->put($hash, true);
            $preparedFiles[] = [
                'file' => $file,
                'hash' => $hash,
                'analysis' => $this->extractor->parseForComparison($file->getRealPath()),
            ];
        }

        $storedPaths = [];

        try {
            DB::transaction(function () use ($management, $preparedFiles, $expectedSerials, $assignedSerialIds, &$storedPaths): void {
                foreach ($preparedFiles as $prepared) {
                    /** @var UploadedFile $file */
                    $file = $prepared['file'];
                    $analysis = $prepared['analysis'];
                    $path = $file->store("vehicle-identification-record-management/{$management->id}/certificates", 'local');
                    $storedPaths[] = $path;
                    $certificate = $management->certificates()->create([
                        'control_number' => $analysis['controlNumber'],
                        'original_file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_hash' => $prepared['hash'],
                        'valid_occurrence_count' => count($analysis['records']),
                        'invalid_count' => count($analysis['invalidRows']),
                        'analyzed_at' => now(),
                    ]);
                    $recordsBySerial = collect($analysis['records'])
                        ->groupBy(fn (array $record): string => Str::upper(trim($record['niv'])));
                    $rows = [];

                    foreach ($recordsBySerial as $serial => $serialRecords) {
                        $occurrences = $serialRecords->count();
                        $sourceData = $serialRecords->first();
                        $requestSerial = $expectedSerials->get($serial);

                        if ($requestSerial === null) {
                            $unexpectedSourceData = [...$sourceData, '_requested' => false];
                            $rows[] = $this->resultRow(
                                $serial,
                                VehicleIdentificationRecordCertificateSerialClassification::Unexpected,
                                $occurrences,
                                'El serial no pertenece a la solicitud relacionada.',
                                $unexpectedSourceData,
                            );

                            if ($occurrences > 1) {
                                $rows[] = $this->resultRow(
                                    $serial,
                                    VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
                                    $occurrences - 1,
                                    'El serial no solicitado está repetido dentro del PDF.',
                                    $unexpectedSourceData,
                                );
                            }

                            continue;
                        }

                        $requestedSourceData = [...$sourceData, '_requested' => true];

                        if ($assignedSerialIds->has($requestSerial->id)) {
                            $rows[] = $this->resultRow(
                                $serial,
                                VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
                                $occurrences,
                                'El serial ya fue certificado por un PDF procesado anteriormente.',
                                $requestedSourceData,
                            );

                            continue;
                        }

                        $rows[] = [
                            ...$this->resultRow(
                                $serial,
                                VehicleIdentificationRecordCertificateSerialClassification::Certified,
                                1,
                                sourceData: $requestedSourceData,
                            ),
                            'request_serial_id' => $requestSerial->id,
                        ];
                        $assignedSerialIds->put($requestSerial->id, true);

                        if ($occurrences > 1) {
                            $rows[] = $this->resultRow(
                                $serial,
                                VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
                                $occurrences - 1,
                                'El serial de la solicitud está repetido dentro del mismo PDF.',
                                $requestedSourceData,
                            );
                        }
                    }

                    foreach ($analysis['invalidRows'] as $invalidRow) {
                        $rows[] = $this->resultRow(
                            trim((string) ($invalidRow['niv'] ?? '')) ?: null,
                            VehicleIdentificationRecordCertificateSerialClassification::Invalid,
                            1,
                            (string) ($invalidRow['reason'] ?? 'La fila no pudo interpretarse.'),
                            $invalidRow,
                        );
                    }

                    foreach (array_chunk($rows, 500) as $rowChunk) {
                        $certificate->serialResults()->createMany($rowChunk);
                    }
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        return $this->summary($management->fresh());
    }

    /** @return array<string, mixed> */
    public function summary(VehicleIdentificationRecordManagement $management): array
    {
        $management->load([
            'motorcycleSerialRequest.lines.serialEntries:id,motorcycle_serial_request_line_id,serial',
            'certificates' => fn ($query) => $query->orderBy('id'),
            'certificates.serialResults' => fn ($query) => $query->orderBy('id'),
        ]);
        $expected = $management->motorcycleSerialRequest->lines->flatMap->serialEntries;
        $results = $management->certificates->flatMap(function ($certificate) {
            return $certificate->serialResults->map(fn ($result): array => [
                'serial' => $result->serial,
                'occurrences' => $result->occurrences,
                'reason' => $result->reason,
                'classification' => $result->classification->value,
                'certificate_id' => $certificate->id,
                'certificate' => $certificate->control_number,
                'imported' => $result->imported_at !== null,
                'requested' => (bool) ($result->source_data['_requested']
                    ?? ! str_contains($result->reason ?? '', 'no solicitado')),
            ]);
        });
        $certified = $results->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Certified->value)->values();
        $certifiedIds = $management->certificates->flatMap->serialResults
            ->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Certified)
            ->pluck('request_serial_id')
            ->filter()
            ->flip();
        $missing = $expected
            ->reject(fn ($serial): bool => $certifiedIds->has($serial->id))
            ->map(fn ($serial): array => ['serial' => $serial->serial, 'certificate' => null])
            ->values();
        $duplicates = $results
            ->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Duplicate->value)
            ->where('requested', true)
            ->values();
        $unexpected = $results->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Unexpected->value)->values();
        $invalid = $results->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Invalid->value)->values();

        return [
            'certificates' => $management->certificates->map(fn ($certificate): array => [
                'id' => $certificate->id,
                'control_number' => $certificate->control_number,
                'original_file_name' => $certificate->original_file_name,
                'analyzed_at' => $certificate->analyzed_at?->format('d/m/Y H:i'),
                'can_delete' => $certificate->serialResults->every(
                    fn ($result): bool => $result->imported_at === null,
                ),
            ])->all(),
            'expectedCount' => $expected->count(),
            'pdfOccurrenceCount' => $management->certificates->sum('valid_occurrence_count'),
            'matchedSerials' => $certified->all(),
            'duplicateSerials' => $duplicates->all(),
            'unexpectedSerials' => $unexpected->all(),
            'missingSerials' => $missing->all(),
            'invalidRows' => $invalid->all(),
            'exactMatch' => $management->certificates->isNotEmpty()
                && $certified->count() === $expected->count()
                && $duplicates->isEmpty()
                && $unexpected->isEmpty()
                && $missing->isEmpty()
                && $invalid->isEmpty(),
        ];
    }

    /** @return array{serial: string|null, classification: string, occurrences: int, reason: string|null, source_data: array<string, mixed>|null} */
    private function resultRow(
        ?string $serial,
        VehicleIdentificationRecordCertificateSerialClassification $classification,
        int $occurrences,
        ?string $reason = null,
        ?array $sourceData = null,
    ): array {
        return [
            'serial' => $serial,
            'classification' => $classification->value,
            'occurrences' => $occurrences,
            'reason' => $reason,
            'source_data' => $sourceData,
        ];
    }
}

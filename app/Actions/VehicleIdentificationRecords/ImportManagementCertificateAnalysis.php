<?php

namespace App\Actions\VehicleIdentificationRecords;

use App\Models\MsCertificado;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagement;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportManagementCertificateAnalysis
{
    public function __construct(private HydrateManagementCertificateSourceData $sourceDataHydrator) {}

    /** @return array{imported: int, certified: int, duplicates: int, invalid: int, skipped: int} */
    public function handle(
        VehicleIdentificationRecordManagement $management,
        bool $includeCertified,
        bool $includeDuplicates,
        bool $includeInvalid,
    ): array {
        $this->sourceDataHydrator->handle($management);

        return Cache::lock("management-certificate-import:{$management->id}", 30)->block(
            5,
            fn (): array => DB::transaction(function () use (
                $management,
                $includeCertified,
                $includeDuplicates,
                $includeInvalid,
            ): array {
                $selected = collect([
                    VehicleIdentificationRecordCertificateSerialClassification::Certified->value => $includeCertified,
                    VehicleIdentificationRecordCertificateSerialClassification::Duplicate->value => $includeDuplicates,
                    VehicleIdentificationRecordCertificateSerialClassification::Invalid->value => $includeInvalid,
                ])->filter()->keys()->all();
                $results = VehicleIdentificationRecordCertificateSerial::query()
                    ->with('certificate:id,management_id,control_number')
                    ->whereHas('certificate', fn ($query) => $query->where('management_id', $management->id))
                    ->whereIn('classification', $selected)
                    ->whereNull('imported_at')
                    ->orderBy('id')
                    ->get();
                $existingNivs = MsCertificado::query()
                    ->whereIn('niv', $results->pluck('serial')->filter()->unique())
                    ->pluck('niv')
                    ->mapWithKeys(fn (string $niv): array => [Str::upper(trim($niv)) => true]);
                $rows = [];
                $processedResultIds = [];
                $counts = ['certified' => 0, 'duplicates' => 0, 'invalid' => 0, 'skipped' => 0];

                foreach ($results as $result) {
                    if (
                        $result->classification === VehicleIdentificationRecordCertificateSerialClassification::Duplicate
                        && ! ($result->source_data['_requested']
                            ?? ! str_contains($result->reason ?? '', 'no solicitado'))
                    ) {
                        continue;
                    }

                    $record = $this->recordFrom($result);

                    if ($record === null) {
                        $counts['skipped']++;

                        continue;
                    }

                    $processedResultIds[] = $result->id;

                    if (
                        $result->classification === VehicleIdentificationRecordCertificateSerialClassification::Certified
                        && $existingNivs->has(Str::upper(trim($record['niv'])))
                    ) {
                        $counts['skipped']++;

                        continue;
                    }

                    $repetitions = $result->classification === VehicleIdentificationRecordCertificateSerialClassification::Certified
                        ? 1
                        : max(1, $result->occurrences);

                    for ($index = 0; $index < $repetitions; $index++) {
                        $rows[] = $record;
                    }

                    $countKey = match ($result->classification) {
                        VehicleIdentificationRecordCertificateSerialClassification::Certified => 'certified',
                        VehicleIdentificationRecordCertificateSerialClassification::Duplicate => 'duplicates',
                        VehicleIdentificationRecordCertificateSerialClassification::Invalid => 'invalid',
                        default => null,
                    };

                    if ($countKey !== null) {
                        $counts[$countKey] += $repetitions;
                    }

                    if ($result->classification === VehicleIdentificationRecordCertificateSerialClassification::Certified) {
                        $existingNivs->put(Str::upper(trim($record['niv'])), true);
                    }
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    $timestamp = now();
                    MsCertificado::query()->insert(array_map(
                        fn (array $row): array => [...$row, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                        $chunk,
                    ));
                }

                VehicleIdentificationRecordCertificateSerial::query()
                    ->whereIn('id', $processedResultIds)
                    ->update(['imported_at' => now()]);

                $management->update([
                    'status' => VehicleIdentificationRecordManagementStatus::Done,
                ]);

                return [
                    'imported' => count($rows),
                    ...$counts,
                ];
            }),
        );
    }

    /** @return array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}|null */
    private function recordFrom(VehicleIdentificationRecordCertificateSerial $result): ?array
    {
        $source = $result->source_data;

        if (! is_array($source)) {
            return null;
        }

        if ($result->classification === VehicleIdentificationRecordCertificateSerialClassification::Invalid) {
            $values = array_pad($source['values'] ?? [], 7, '');

            return $this->normalizeRecord([
                'no' => $values[0],
                'marca' => $values[1],
                'modelo' => $values[2],
                'tipo' => $values[3],
                'fabricacion' => $values[4],
                'anio' => $values[5],
                'niv' => $values[6],
                'codigo' => $result->certificate->control_number,
            ]);
        }

        return $this->normalizeRecord([
            ...$source,
            'niv' => $result->serial ?? ($source['niv'] ?? ''),
            'codigo' => $source['codigo'] ?? $result->certificate->control_number,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}
     */
    private function normalizeRecord(array $record): array
    {
        return [
            'no' => Str::substr((string) ($record['no'] ?? ''), 0, 50),
            'marca' => Str::substr((string) ($record['marca'] ?? ''), 0, 100),
            'modelo' => Str::substr((string) ($record['modelo'] ?? ''), 0, 100),
            'tipo' => Str::substr((string) ($record['tipo'] ?? ''), 0, 100),
            'fabricacion' => Str::substr((string) ($record['fabricacion'] ?? ''), 0, 100),
            'anio' => ctype_digit((string) ($record['anio'] ?? '')) ? (int) $record['anio'] : 0,
            'niv' => Str::substr(Str::upper((string) ($record['niv'] ?? '')), 0, 50),
            'codigo' => Str::substr((string) ($record['codigo'] ?? ''), 0, 100),
        ];
    }
}

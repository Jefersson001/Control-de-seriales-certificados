<?php

namespace App\Actions\VehicleIdentificationRecords;

use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DeleteManagementCertificate
{
    public function handle(VehicleIdentificationRecordManagementCertificate $certificate): void
    {
        if ($certificate->serialResults()->whereNotNull('imported_at')->exists()) {
            throw new RuntimeException('No se puede quitar un certificado que ya fue importado al maestro.');
        }

        $filePath = $certificate->file_path;

        DB::transaction(function () use ($certificate): void {
            $management = $certificate->management()->firstOrFail();
            $certificate->delete();
            $management->load('motorcycleSerialRequest.lines.serialEntries');
            $expectedSerialIds = $management->motorcycleSerialRequest->lines
                ->flatMap->serialEntries
                ->mapWithKeys(fn ($entry): array => [Str::upper(trim($entry->serial)) => $entry->id]);
            $remainingResults = VehicleIdentificationRecordCertificateSerial::query()
                ->with('certificate')
                ->whereHas('certificate', fn ($query) => $query->where('management_id', $management->id))
                ->orderBy('certificate_id')
                ->orderBy('id')
                ->get();
            $assignedSerials = $remainingResults
                ->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Certified)
                ->pluck('serial')
                ->flip();

            foreach ($remainingResults as $result) {
                if (
                    $result->classification !== VehicleIdentificationRecordCertificateSerialClassification::Duplicate
                    || $result->serial === null
                    || $assignedSerials->has($result->serial)
                    || ! $expectedSerialIds->has($result->serial)
                ) {
                    continue;
                }

                $remainingOccurrences = $result->occurrences - 1;
                $result->update([
                    'request_serial_id' => $expectedSerialIds->get($result->serial),
                    'classification' => VehicleIdentificationRecordCertificateSerialClassification::Certified,
                    'occurrences' => 1,
                    'reason' => null,
                ]);
                $assignedSerials->put($result->serial, true);

                if ($remainingOccurrences > 0) {
                    $result->certificate->serialResults()->create([
                        'classification' => VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
                        'serial' => $result->serial,
                        'occurrences' => $remainingOccurrences,
                        'reason' => 'El serial de la solicitud está repetido dentro del mismo PDF.',
                    ]);
                }
            }
        });

        Storage::disk('local')->delete($filePath);
    }
}

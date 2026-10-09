<?php

namespace App\Actions\Certificates;

use App\Models\CertificateDocument;
use App\Models\MsCertificado;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RecoverCertificateEmissionDates
{
    public function __construct(private ImportCertificatesFromPdf $extractor) {}

    /** @return array{updated: int, skipped: int} */
    public function handle(?string $controlNumber = null): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ([VehicleIdentificationRecordManagementCertificate::class, CertificateDocument::class] as $modelClass) {
            $certificates = $modelClass::query()
                ->when($controlNumber !== null, fn ($query) => $query->where('control_number', $controlNumber))
                ->lazyById(100);

            foreach ($certificates as $certificate) {
                $issuedOn = $certificate->issued_on?->format('Y-m-d');

                if ($issuedOn === null) {
                    if (! Storage::disk('local')->exists($certificate->file_path)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $issuedOn = $this->extractor->extractIssuedOn(Storage::disk('local')->path($certificate->file_path));
                    } catch (Throwable $exception) {
                        report($exception);
                        $skipped++;

                        continue;
                    }
                }

                if ($issuedOn === null) {
                    $skipped++;

                    continue;
                }

                $updated += DB::transaction(function () use ($certificate, $issuedOn): int {
                    $values = ['issued_on' => $issuedOn];
                    $controlNumber = $certificate->control_number;

                    return VehicleIdentificationRecordManagementCertificate::query()
                        ->where('control_number', $controlNumber)->whereNull('issued_on')->update($values)
                        + CertificateDocument::query()
                            ->where('control_number', $controlNumber)->whereNull('issued_on')->update($values)
                        + MsCertificado::query()
                            ->where('codigo', $controlNumber)->whereNull('issued_on')->update($values);
                });
            }
        }

        return compact('updated', 'skipped');
    }
}

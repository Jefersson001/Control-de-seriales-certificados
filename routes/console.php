<?php

use App\Actions\Certificates\LoadCertificateDocumentSerialCounts;
use App\Actions\Certificates\RecoverCertificateEmissionDates;
use App\Actions\Certificates\RecoverCertificateSerialCount;
use App\Models\CertificateDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('certificates:recover-emission-dates {certificate?}', function (RecoverCertificateEmissionDates $recovery): void {
    $result = $recovery->handle($this->argument('certificate'));
    $this->info("Fechas recuperadas: {$result['updated']} registros. Archivos omitidos: {$result['skipped']}.");
})->purpose('Recuperar fechas de emisión desde los PDF guardados sin volver a importar seriales');

Artisan::command('certificates:recover-serial-counts {--document=}', function (
    LoadCertificateDocumentSerialCounts $serialCounts,
    RecoverCertificateSerialCount $recovery,
): int {
    if ($this->option('document') !== null) {
        return $recovery->handle(CertificateDocument::query()->findOrFail($this->option('document'))) ? 0 : 1;
    }

    $updated = 0;
    $failed = 0;

    CertificateDocument::query()->whereNull('total_serial_count')
        ->chunkById(200, function (Collection $documents) use ($serialCounts, $recovery, &$updated, &$failed): void {
            $serialCounts->handle($documents);

            foreach ($documents as $document) {
                if ($document->total_serial_count !== null) {
                    $success = $recovery->handle($document);
                } else {
                    $process = new Process([
                        PHP_BINARY, base_path('artisan'), 'certificates:recover-serial-counts',
                        '--document='.$document->id, '--no-interaction',
                    ], base_path());
                    $process->setTimeout(300);

                    try {
                        $success = $process->run() === 0;
                    } catch (ProcessTimedOutException $exception) {
                        report($exception);
                        $success = false;
                    }
                }

                if ($success) {
                    $updated++;
                } else {
                    $failed++;
                    $this->warn('No se pudo recuperar la cantidad del documento #'.$document->id);
                }

                $this->line('Documentos revisados: '.($updated + $failed));
            }
        });

    $this->info("Cantidades recuperadas: {$updated}. Documentos pendientes: {$failed}.");

    return $failed === 0 ? 0 : 1;
})->purpose('Guardar los totales de seriales existentes procesando cada PDF por separado');

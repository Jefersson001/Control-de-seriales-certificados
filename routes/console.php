<?php

use App\Actions\Certificates\RecoverCertificateEmissionDates;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('certificates:recover-emission-dates {certificate?}', function (RecoverCertificateEmissionDates $recovery): void {
    $result = $recovery->handle($this->argument('certificate'));
    $this->info("Fechas recuperadas: {$result['updated']} registros. Archivos omitidos: {$result['skipped']}.");
})->purpose('Recuperar fechas de emisión desde los PDF guardados sin volver a importar seriales');

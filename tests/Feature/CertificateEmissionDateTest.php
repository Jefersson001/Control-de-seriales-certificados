<?php

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Models\CertificateDocument;
use App\Models\MsCertificado;
use App\Models\User;
use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('emission dates are extracted after the label regardless of whitespace', function (string $label, ?string $expected) {
    $text = "NÚMERO DE CONTROL.: DG-NIV-RG1-0362-PC\n{$label}\n1  BERA \tBR 150 BRF MOTOCICLETA 2026 2026 8YZC7MCC0TD033213";

    $analysis = app(ImportCertificatesFromPdf::class)->parseText($text);

    expect($analysis['issuedOn'])->toBe($expected)
        ->and($analysis['controlNumber'])->toBe('DG-NIV-RG1-0362-PC');
})->with([
    ['Fecha de Emisión:28-08-2026', '2026-08-28'],
    ['Fecha de Emisión:           28-08-2026', '2026-08-28'],
    ["Fecha de Emisión:\n\t28/08/2026", '2026-08-28'],
    ['FECHA DE EMISION: 1 - 2 - 2026', '2026-02-01'],
    ['Fecha de Emisión: 30-02-2026', null],
    ['Fecha de Emisión: sin fecha', null],
    ['', null],
]);

test('analysis and imported records retain the emission date in all requested categories', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'request_date' => '2026-08-10',
    ]);
    $line = $management->motorcycleSerialRequest->lines()->firstOrFail();
    $line->serialEntries()->delete();
    $line->serialEntries()->createMany([
        ['serial' => '8YZC7MCC0TD000001'],
        ['serial' => '8YZC7MCC0TD000002'],
    ]);
    $source = [
        'marca' => 'BERA', 'modelo' => 'BR 150', 'tipo' => 'MOTOCICLETA',
        'fabricacion' => '2026', 'anio' => 2026, 'codigo' => 'DG-NIV-RG1-0362-PC',
    ];
    $extractor = $this->mock(ImportCertificatesFromPdf::class);
    $extractor->shouldReceive('parseForComparison')->once()->andReturn([
        'controlNumber' => 'DG-NIV-RG1-0362-PC',
        'issuedOn' => '2026-08-28',
        'records' => [
            [...$source, 'no' => '1', 'niv' => '8YZC7MCC0TD000001'],
            [...$source, 'no' => '2', 'niv' => '8YZC7MCC0TD000002'],
            [...$source, 'no' => '3', 'niv' => '8YZC7MCC0TD000002'],
            [...$source, 'no' => '4', 'niv' => '8YZC7MCC0TD999999'],
        ],
        'invalidCount' => 0, 'invalidRows' => [],
    ]);
    $this->actingAs($administrator);

    $component = Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->set('pdfFiles', [UploadedFile::fake()->createWithContent('emision.pdf', '%PDF-emission')])
        ->call('processNextPdf')
        ->assertHasNoErrors()
        ->assertSee('Fecha de emisión')
        ->assertSee('28/08/2026')
        ->assertSet('matchedSerials.0.issued_on', '28/08/2026')
        ->assertSet('duplicateSerials.0.issued_on', '28/08/2026')
        ->assertSet('unexpectedSerials.0.issued_on', '28/08/2026');

    expect($management->certificates()->sole()->issued_on->format('Y-m-d'))->toBe('2026-08-28');

    $component->set('includeDuplicates', true)
        ->set('includeUnexpected', true)
        ->call('importCertificateSelection')
        ->assertHasNoErrors();

    expect(MsCertificado::query()->count())->toBe(4)
        ->and(MsCertificado::query()->whereDate('issued_on', '2026-08-28')->count())->toBe(4)
        ->and(CertificateDocument::query()->sole()->issued_on->format('Y-m-d'))->toBe('2026-08-28');

    Livewire::test('certificate-document-list')
        ->assertSee('Fecha de emisión')
        ->assertSee('28/08/2026')
        ->assertSee('10/08/2026');

    Livewire::test('certificate-master')
        ->assertSee('Fecha de emisión')
        ->assertSee('28/08/2026')
        ->assertSee('10/08/2026')
        ->set('recordFilter', 'group_by_certificate')
        ->assertSee('28/08/2026');
});

test('direct pdf imports retain emission dates for ready duplicate and invalid rows', function () {
    $text = "NÚMERO DE CONTROL.: DG-NIV-RG1-0362-PC\nFecha de Emisión: 28-08-2026\n1  BERA \tBR 150 BRF MOTOCICLETA 2026 2026 8YZC7MCC0TD033213";
    $importer = app(ImportCertificatesFromPdf::class);
    $analysis = $importer->parseText($text);
    $analysis['duplicateRows'] = [['values' => ['2', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', '2026', '8YZC7MCC0TD033214']]];
    $analysis['invalidRows'] = [['values' => ['3', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', '2026', 'INVALIDO']]];

    $result = $importer->storeSelection($analysis, true, true, true);

    expect($result['imported'])->toBe(3)
        ->and(MsCertificado::query()->whereDate('issued_on', '2026-08-28')->count())->toBe(3);
});

test('stored pdf dates are recovered without duplicating serials or overwriting existing dates', function () {
    Storage::fake('local');
    $pdf = new FPDF;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, iconv('UTF-8', 'Windows-1252', 'Fecha de Emisión:           28-08-2026'));
    Storage::disk('local')->put('recovery.pdf', $pdf->Output('S'));
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create([
        'control_number' => 'RECOVER-EMISSION',
        'file_path' => 'recovery.pdf',
        'issued_on' => null,
    ]);
    $document = CertificateDocument::query()->create([
        'control_number' => 'RECOVER-EMISSION',
        'file_name' => 'recovery.pdf',
        'original_file_name' => 'recovery.pdf',
        'file_path' => 'recovery.pdf',
    ]);
    $document->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();
    $missingDate = MsCertificado::factory()->create(['codigo' => 'RECOVER-EMISSION']);
    $existingDate = MsCertificado::factory()->create([
        'codigo' => 'RECOVER-EMISSION', 'issued_on' => '2026-08-20',
    ]);
    $unrelated = MsCertificado::factory()->create(['codigo' => 'UNRELATED']);

    $this->artisan('certificates:recover-emission-dates', ['certificate' => 'RECOVER-EMISSION'])
        ->expectsOutput('Fechas recuperadas: 3 registros. Archivos omitidos: 0.')
        ->assertSuccessful();

    expect($certificate->refresh()->issued_on->format('Y-m-d'))->toBe('2026-08-28')
        ->and($document->refresh()->issued_on->format('Y-m-d'))->toBe('2026-08-28')
        ->and($document->created_at->format('Y-m-d H:i:s'))->toBe('2026-08-01 10:00:00')
        ->and($missingDate->refresh()->issued_on->format('Y-m-d'))->toBe('2026-08-28')
        ->and($existingDate->refresh()->issued_on->format('Y-m-d'))->toBe('2026-08-20')
        ->and($unrelated->refresh()->issued_on)->toBeNull()
        ->and(MsCertificado::query()->count())->toBe(3);

    $this->artisan('certificates:recover-emission-dates', ['certificate' => 'RECOVER-EMISSION'])
        ->expectsOutput('Fechas recuperadas: 0 registros. Archivos omitidos: 0.')
        ->assertSuccessful();
});

test('emission date recovery skips missing pdf files', function () {
    Storage::fake('local');
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create([
        'control_number' => 'MISSING-PDF', 'file_path' => 'missing.pdf',
    ]);

    $this->artisan('certificates:recover-emission-dates', ['certificate' => 'MISSING-PDF'])
        ->expectsOutput('Fechas recuperadas: 0 registros. Archivos omitidos: 1.')
        ->assertSuccessful();

    expect($certificate->refresh()->issued_on)->toBeNull();
});

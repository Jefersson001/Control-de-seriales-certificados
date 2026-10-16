<?php

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\CertificateStatus;
use App\Models\CertificateDocument;
use App\Models\MsCertificado;
use App\Models\User;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\UserPermission;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificateDocuments->value],
    ]);
    $this->document = CertificateDocument::query()->forceCreate([
        'control_number' => 'CERT-REPORTE',
        'file_name' => 'CERT-REPORTE.pdf',
        'original_file_name' => 'original.pdf',
        'file_path' => 'certificate-documents/reporte.pdf',
        'issued_on' => '2026-01-12',
        'created_at' => '2026-01-15 10:30:00',
    ]);
});

test('the excel report contains every displayed field including current serial counts', function () {
    $management = VehicleIdentificationRecordManagement::factory()->create(['request_date' => '2026-01-10']);
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create([
        'management_id' => $management->id,
        'control_number' => $this->document->control_number,
        'valid_occurrence_count' => 1000,
    ]);
    $this->document->managements()->attach($management);
    VehicleIdentificationRecordCertificateSerial::factory()->count(3)->create([
        'certificate_id' => $certificate->id,
        'imported_at' => now(),
    ]);
    foreach (CertificateStatus::cases() as $status) {
        MsCertificado::factory()->create(['codigo' => $this->document->control_number, 'status' => $status]);
    }

    $response = $this->actingAs($this->user)->get(route('certificate_documents.export'));
    $response->assertSuccessful()->assertDownload('reporte-certificados-'.now()->format('Y-m-d').'.xlsx');
    $rows = readCertificateDocumentReport($response->baseResponse);

    expect($rows)->toBe([
        ['Certificado', 'Gestión', 'Cantidad de Seriales en el Certificados', 'Cantidad de Seriales Certificados Procesados', 'Seriales despachados', 'Seriales por despachar', 'Seriales devueltos', 'Fecha', 'Fecha de certificación', 'Fecha de emisión'],
        ['CERT-REPORTE', 'Gestión #'.$management->id, 1000, 3, 1, 1, 1, '15/01/2026 10:30', '10/01/2026', '12/01/2026'],
    ]);
});

test('the report exports all pages and represents missing information as in the list', function () {
    $this->mock(ImportCertificatesFromPdf::class)->shouldNotReceive('parseForComparison');

    foreach (range(1, 244) as $index) {
        CertificateDocument::query()->create([
            'control_number' => 'CERT-'.$index,
            'file_name' => 'CERT-'.$index.'.pdf',
            'original_file_name' => 'original.pdf',
            'file_path' => 'certificate-documents/'.$index.'.pdf',
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('certificate_documents.export', ['perPage' => 1, 'page' => 2]));
    $response->assertSuccessful();
    $rows = readCertificateDocumentReport($response->baseResponse);

    expect($rows)->toHaveCount(246)
        ->and($rows[1][1])->toBe('Sin relación')
        ->and($rows[1][2])->toBe('—')
        ->and($rows[1][3])->toBe(0)
        ->and(array_slice($rows[1], 4, 3))->toBe([0, 0, 0])
        ->and($rows[1][8])->toBe('Sin registrar')
        ->and($rows[1][9])->toBe('Sin registrar');
});

test('the report respects search and each date filter', function (string $dateField) {
    $management = VehicleIdentificationRecordManagement::factory()->create(['request_date' => '2026-01-10']);
    $this->document->managements()->attach($management);
    CertificateDocument::query()->forceCreate([
        'control_number' => 'CERT-FUERA-DE-FECHA',
        'file_name' => 'fuera.pdf',
        'original_file_name' => 'original.pdf',
        'file_path' => 'fuera.pdf',
        'issued_on' => '2025-01-01',
        'created_at' => '2025-01-01',
    ]);
    CertificateDocument::query()->forceCreate([
        'control_number' => 'OTRO',
        'file_name' => 'otro.pdf',
        'original_file_name' => 'original.pdf',
        'file_path' => 'otro.pdf',
        'issued_on' => '2026-01-12',
        'created_at' => '2026-01-15',
    ])->managements()->attach($management);

    $response = $this->actingAs($this->user)->get(route('certificate_documents.export', [
        'search' => 'CERT-',
        'dateField' => $dateField,
        'dateFrom' => '2026-01-01',
        'dateTo' => '2026-01-31',
    ]));
    $response->assertSuccessful();
    $rows = readCertificateDocumentReport($response->baseResponse);

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('CERT-REPORTE');
})->with(['created_at', 'issued_on', 'request_date']);

test('the export button carries the active list filters', function () {
    $this->actingAs($this->user);

    Livewire::test('certificate-document-list')
        ->set('search', 'CERT-REPORTE')
        ->set('dateField', 'issued_on')
        ->set('dateFrom', '2026-01-01')
        ->set('dateTo', '2026-01-31')
        ->assertSee('Exportar Excel')
        ->assertSee(route('certificate_documents.export', [
            'search' => 'CERT-REPORTE',
            'dateField' => 'issued_on',
            'dateFrom' => '2026-01-01',
            'dateTo' => '2026-01-31',
        ]));
});

test('the report excludes the pdf filename column', function () {
    $this->document->update(['file_name' => '=1+1.pdf']);

    $response = $this->actingAs($this->user)->get(route('certificate_documents.export'));
    $response->assertSuccessful();
    $rows = readCertificateDocumentReport($response->baseResponse);

    expect($rows[0])->toHaveCount(10)->not->toContain('Archivo PDF')
        ->and($rows[1])->toHaveCount(10)->not->toContain('=1+1.pdf');
});

test('the certificate report requires the document viewing permission', function () {
    $this->get(route('certificate_documents.export'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())
        ->get(route('certificate_documents.export'))->assertForbidden();
});

test('the certificate report rejects invalid date ranges', function () {
    $this->actingAs($this->user)->getJson(route('certificate_documents.export', [
        'dateField' => 'issued_on',
        'dateFrom' => '2026-02-01',
        'dateTo' => '2026-01-01',
    ]))->assertUnprocessable()->assertJsonValidationErrors('dateTo');
});

/** @return list<list<mixed>> */
function readCertificateDocumentReport(BinaryFileResponse $response): array
{
    $path = $response->getFile()->getPathname();
    $reader = new Reader;
    $reader->open($path);

    try {
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        return $rows;
    } finally {
        $reader->close();
        unlink($path);
    }
}

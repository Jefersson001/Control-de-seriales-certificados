<?php

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Actions\Certificates\StoreCertificateDocument;
use App\Models\CertificateDocument;
use App\Models\User;
use App\Models\VehicleIdentificationRecordManagement;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('confirmed pdf files are stored using their certificate control number', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewVehicleIdentificationRecord->value],
    ]);
    $pdf = UploadedFile::fake()->createWithContent(
        'un-nombre-cualquiera.pdf',
        '%PDF-1.4 contenido de prueba',
    );

    $this->actingAs($user);

    $document = app(StoreCertificateDocument::class)
        ->handle($pdf, 'dg-niv-rg5-0175-pc');

    expect($document->control_number)->toBe('DG-NIV-RG5-0175-PC')
        ->and($document->file_name)->toBe('DG-NIV-RG5-0175-PC.pdf')
        ->and($document->original_file_name)->toBe('un-nombre-cualquiera.pdf')
        ->and($document->imported_without_management)->toBeTrue()
        ->and($document->managements()->count())->toBe(0)
        ->and($document->uploaded_by)->toBe($user->id);
    Storage::disk('local')->assertExists($document->file_path);
});

test('the same certificate control number is stored only once', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $otherManagement = VehicleIdentificationRecordManagement::factory()->create();
    $firstPdf = UploadedFile::fake()->createWithContent('primero.pdf', '%PDF-1.4 primero');
    $secondPdf = UploadedFile::fake()->createWithContent('segundo.pdf', '%PDF-1.4 segundo');

    $this->actingAs($user);

    $first = app(StoreCertificateDocument::class)
        ->handle($firstPdf, 'DG-NIV-RG5-0175-PC');
    $repeated = app(StoreCertificateDocument::class)
        ->handle($secondPdf, ' dg-niv-rg5-0175-pc ', $management->id);
    app(StoreCertificateDocument::class)
        ->handle($secondPdf, 'DG-NIV-RG5-0175-PC', $otherManagement->id);

    expect(CertificateDocument::query()->count())->toBe(1)
        ->and($repeated->is($first))->toBeTrue()
        ->and($repeated->imported_without_management)->toBeTrue()
        ->and($repeated->managements()->pluck('management_id')->sort()->values()->all())
        ->toBe(collect([$management->id, $otherManagement->id])->sort()->values()->all())
        ->and(Storage::disk('local')->allFiles('certificate-documents'))->toHaveCount(1);
});

test('confirming the pdf import creates its unrelated certificate document', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewVehicleIdentificationRecord->value],
    ]);
    $pdf = UploadedFile::fake()->create(
        'nombre-distinto-al-certificado.pdf',
        10,
        'application/pdf',
    );
    $analysis = [
        'controlNumber' => 'DG-NIV-RG5-0999-PC',
        'records' => [[
            'no' => '1',
            'marca' => 'BERA',
            'modelo' => 'BR 150',
            'tipo' => 'MOTOCICLETA',
            'fabricacion' => '2026',
            'anio' => 2026,
            'niv' => '8YZC7MCC0TD099999',
            'codigo' => 'DG-NIV-RG5-0999-PC',
        ]],
        'duplicateCount' => 0,
        'duplicateRows' => [],
        'invalidCount' => 0,
        'invalidRows' => [],
    ];
    $importer = $this->mock(ImportCertificatesFromPdf::class);
    $importer->shouldReceive('parse')->once()->andReturn($analysis);
    $importer->shouldReceive('storeSelection')->once()->andReturn([
        'imported' => 1,
        'ready' => 1,
        'duplicates' => 0,
        'invalid' => 0,
        'skipped' => 0,
    ]);

    $this->actingAs($user);

    Livewire::test('vehicle-identification-record-import')
        ->set('pdfFile', $pdf)
        ->call('analyzePdf')
        ->assertHasNoErrors()
        ->call('confirmImport')
        ->assertHasNoErrors();

    $document = CertificateDocument::query()->sole();

    expect($document->file_name)->toBe('DG-NIV-RG5-0999-PC.pdf')
        ->and($document->imported_without_management)->toBeTrue()
        ->and($document->managements()->count())->toBe(0);
    Storage::disk('local')->assertExists($document->file_path);
});

test('authorized users can search and download certificate documents', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificateDocuments->value],
    ]);
    $visible = CertificateDocument::query()->create([
        'uploaded_by' => $user->id,
        'control_number' => 'DG-NIV-RG5-0175-PC',
        'file_name' => 'DG-NIV-RG5-0175-PC.pdf',
        'original_file_name' => 'archivo.pdf',
        'file_path' => 'certificate-documents/visible.pdf',
    ]);
    CertificateDocument::query()->create([
        'uploaded_by' => $user->id,
        'control_number' => 'OTRO-CERTIFICADO',
        'file_name' => 'OTRO-CERTIFICADO.pdf',
        'original_file_name' => 'otro.pdf',
        'file_path' => 'certificate-documents/otro.pdf',
    ]);
    Storage::disk('local')->put($visible->file_path, '%PDF-1.4');

    $this->actingAs($user);

    Livewire::test('certificate-document-list')
        ->assertSee('DG-NIV-RG5-0175-PC')
        ->assertSee('OTRO-CERTIFICADO')
        ->assertSee('Sin relación')
        ->set('search', '0175')
        ->assertSee('DG-NIV-RG5-0175-PC')
        ->assertDontSee('OTRO-CERTIFICADO');

    $this->get(route('certificate_documents.download', $visible))
        ->assertSuccessful()
        ->assertDownload('DG-NIV-RG5-0175-PC.pdf');
});

test('certificate document module is read only and protected by permission', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $unauthorizedUser = User::factory()->create();

    $this->actingAs($administrator)
        ->get(route('certificate_documents.index'))
        ->assertSuccessful()
        ->assertSee('Buscar certificado')
        ->assertDontSee('Crear certificado');

    $this->actingAs($unauthorizedUser)
        ->get(route('certificate_documents.index'))
        ->assertForbidden();
});

test('authorized users can delete a certificate record and its physical pdf', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewCertificateDocuments->value,
            UserPermission::DeleteCertificateDocuments->value,
        ],
    ]);
    $document = CertificateDocument::query()->create([
        'uploaded_by' => $user->id,
        'control_number' => 'DG-NIV-RG5-DELETE-PC',
        'file_name' => 'DG-NIV-RG5-DELETE-PC.pdf',
        'original_file_name' => 'eliminar.pdf',
        'file_path' => 'certificate-documents/eliminar.pdf',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4');

    $this->actingAs($user);

    Livewire::test('certificate-document-list')
        ->call('openDeleteConfirmation', $document->id)
        ->assertSet('documentPendingDeletionId', $document->id)
        ->call('deleteDocument')
        ->assertHasNoErrors()
        ->assertSee('fueron eliminados correctamente');

    expect(CertificateDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->file_path);
});

test('deleting a management removes only its certificate relation', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $pdf = UploadedFile::fake()->createWithContent('compartido.pdf', '%PDF-1.4 compartido');

    $this->actingAs($user);

    $document = app(StoreCertificateDocument::class)
        ->handle($pdf, 'DG-NIV-RG5-SHARED-PC', $management->id);
    $path = $document->file_path;

    $management->delete();

    expect($document->fresh())->not->toBeNull()
        ->and($document->fresh()->managements()->count())->toBe(0);
    Storage::disk('local')->assertExists($path);
});

test('certificate master is the first grouped menu and contains both modules', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('data-certificate-master-menu', false)
        ->assertSee(route('certificates.index'))
        ->assertSee(route('certificate_documents.index'))
        ->assertSeeInOrder([
            'data-certificate-master-menu',
            'data-configuration-menu',
        ], false)
        ->assertSee('group order-[998]', false)
        ->assertSee('group order-[999]', false);
});

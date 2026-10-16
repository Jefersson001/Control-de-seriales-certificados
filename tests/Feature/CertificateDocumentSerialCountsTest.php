<?php

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Actions\Certificates\LoadCertificateDocumentSerialCounts;
use App\Actions\Certificates\RecoverCertificateSerialCount;
use App\Actions\Certificates\StoreCertificateDocument;
use App\Actions\Dispatches\FinalizeDispatch;
use App\Actions\Returns\FinalizeReturn;
use App\CertificateStatus;
use App\Models\CertificateDocument;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use App\Models\ProductReturn;
use App\Models\User;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
});

test('certificate counts progress only when imports are confirmed and remain separate per pdf', function () {
    $user = User::factory()->create(['permissions' => [UserPermission::ViewCertificateDocuments->value]]);
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create([
        'valid_occurrence_count' => 2,
    ]);
    $document = CertificateDocument::query()->create([
        'control_number' => $certificate->control_number,
        'file_name' => 'certificado.pdf',
        'original_file_name' => 'certificado.pdf',
        'file_path' => $certificate->file_path,
    ]);
    $document->managements()->attach($certificate->management_id);
    $first = VehicleIdentificationRecordCertificateSerial::factory()->create([
        'certificate_id' => $certificate->id,
        'serial' => '8YZC7MCC0TD000001',
    ]);
    $second = VehicleIdentificationRecordCertificateSerial::factory()->create([
        'certificate_id' => $certificate->id,
        'serial' => '8YZC7MCC0TD000002',
    ]);
    VehicleIdentificationRecordCertificateSerial::factory()->create(['imported_at' => now()]);

    $this->actingAs($user);
    $component = Livewire::test('certificate-document-list')
        ->assertSeeHtml('wire:poll.15s')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->total_serial_count === 2
            && $documents->sole()->processed_serial_count === 0);

    $first->update(['imported_at' => now()]);
    $component->call('$refresh')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->total_serial_count === 2
            && $documents->sole()->processed_serial_count === 1);

    $second->update(['imported_at' => now()]);
    $component->call('$refresh')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->total_serial_count === 2
            && $documents->sole()->processed_serial_count === 2);
});

test('processing the same certificate in several managements does not multiply its counts', function () {
    $certificates = VehicleIdentificationRecordManagementCertificate::factory()->count(2)->create([
        'control_number' => 'CERTIFICADO-COMPARTIDO',
        'valid_occurrence_count' => 1000,
    ]);
    $document = new CertificateDocument(['control_number' => 'CERTIFICADO-COMPARTIDO']);

    foreach ($certificates as $certificate) {
        VehicleIdentificationRecordCertificateSerial::factory()->create([
            'certificate_id' => $certificate->id,
            'serial' => '8YZC7MCC0TD000001',
            'imported_at' => now(),
        ]);
    }
    VehicleIdentificationRecordCertificateSerial::factory()->create([
        'certificate_id' => $certificates->last()->id,
        'serial' => '8YZC7MCC0TD000002',
        'imported_at' => now(),
    ]);

    app(LoadCertificateDocumentSerialCounts::class)->handle(collect([$document]));

    expect($document->total_serial_count)->toBe(1000)
        ->and($document->processed_serial_count)->toBe(2);
});

test('complete loads use the stored total and distinct imported serials without reading the pdf', function () {
    $document = new CertificateDocument([
        'control_number' => 'CARGA-COMPLETA',
        'imported_without_management' => true,
        'file_path' => 'certificate-documents/completa.pdf',
        'total_serial_count' => 2,
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4');
    $extractor = $this->mock(ImportCertificatesFromPdf::class);
    $extractor->shouldNotReceive('parseForComparison');
    MsCertificado::factory()->count(2)->create([
        'codigo' => $document->control_number,
        'niv' => '8YZC7MCC0TD000001',
    ]);
    $counter = app(LoadCertificateDocumentSerialCounts::class);
    $counter->handle(collect([$document]));

    expect($document->total_serial_count)->toBe(2)
        ->and($document->processed_serial_count)->toBe(1);

    MsCertificado::factory()->create([
        'codigo' => $document->control_number,
        'niv' => '8YZC7MCC0TD000002',
    ]);
    $counter->handle(collect([$document]));

    expect($document->total_serial_count)->toBe(2)
        ->and($document->processed_serial_count)->toBe(2);
});

test('a complete load later linked to management does not count the same serial twice', function () {
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create(['valid_occurrence_count' => 2]);
    $document = new CertificateDocument([
        'control_number' => $certificate->control_number,
        'imported_without_management' => true,
    ]);
    VehicleIdentificationRecordCertificateSerial::factory()->create([
        'certificate_id' => $certificate->id,
        'serial' => '8YZC7MCC0TD000001',
        'imported_at' => now(),
    ]);
    MsCertificado::factory()->create(['codigo' => $certificate->control_number, 'niv' => '8YZC7MCC0TD000001']);
    MsCertificado::factory()->create(['codigo' => $certificate->control_number, 'niv' => '8YZC7MCC0TD000002']);

    app(LoadCertificateDocumentSerialCounts::class)->handle(collect([$document]));

    expect($document->total_serial_count)->toBe(2)
        ->and($document->processed_serial_count)->toBe(2);
});

test('missing pdfs display an unknown total rather than a false zero', function () {
    $this->mock(ImportCertificatesFromPdf::class)->shouldNotReceive('parseForComparison');
    $document = new CertificateDocument([
        'control_number' => 'SIN-ARCHIVO',
        'file_path' => 'certificate-documents/inexistente.pdf',
    ]);

    app(LoadCertificateDocumentSerialCounts::class)->handle(collect([$document]));

    expect($document->total_serial_count)->toBeNull()
        ->and($document->processed_serial_count)->toBe(0);
});

test('legacy cached totals remain available without opening pdfs', function () {
    $document = new CertificateDocument([
        'control_number' => 'CON-CACHE',
        'file_path' => 'certificate-documents/cache.pdf',
    ]);
    Cache::put('certificate-document-serial-count:'.hash('sha256', $document->file_path), 1000);
    $this->mock(ImportCertificatesFromPdf::class)->shouldNotReceive('parseForComparison');

    app(LoadCertificateDocumentSerialCounts::class)->handle(collect([$document]));

    expect($document->total_serial_count)->toBe(1000);
});

test('recovering a legacy pdf stores its total permanently for later exports', function () {
    $document = CertificateDocument::query()->create([
        'control_number' => 'LEGACY',
        'file_name' => 'legacy.pdf',
        'original_file_name' => 'legacy.pdf',
        'file_path' => 'certificate-documents/legacy.pdf',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4');
    $this->mock(ImportCertificatesFromPdf::class)->shouldReceive('parseForComparison')->once()->andReturn([
        'records' => [['niv' => '8YZC7MCC0TD000001']],
        'invalidCount' => 1,
    ]);

    $this->artisan('certificates:recover-serial-counts', ['--document' => $document->id])->assertSuccessful();
    Cache::flush();
    $document->refresh();
    app(LoadCertificateDocumentSerialCounts::class)->handle(collect([$document]));

    expect($document->total_serial_count)->toBe(2)
        ->and(app(RecoverCertificateSerialCount::class)->handle($document))->toBeTrue();
});

test('imports store the complete pdf total and preserve it across partial management imports', function () {
    $pdf = UploadedFile::fake()->createWithContent('total.pdf', '%PDF-1.4');
    $store = app(StoreCertificateDocument::class);
    $document = $store->handle($pdf, 'TOTAL-1000', totalSerialCount: 1000);
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $store->handle($pdf, 'TOTAL-1000', $management->id, totalSerialCount: 500);

    expect($document->fresh()->total_serial_count)->toBe(1000);
});

test('certificate dispatch counts use registered serial states and exclude other certificates and blank serials', function () {
    $document = new CertificateDocument(['control_number' => 'ESTADOS', 'total_serial_count' => 1000]);
    foreach (CertificateStatus::cases() as $status) {
        MsCertificado::factory()->create(['codigo' => 'ESTADOS', 'status' => $status]);
        MsCertificado::factory()->create(['codigo' => 'OTRO', 'status' => $status]);
    }
    MsCertificado::factory()->create(['codigo' => 'ESTADOS', 'niv' => '   ', 'status' => CertificateStatus::Dispatched]);

    app(LoadCertificateDocumentSerialCounts::class)->handle(collect([$document]));

    expect($document->dispatched_serial_count)->toBe(1)
        ->and($document->pending_dispatch_serial_count)->toBe(1)
        ->and($document->returned_serial_count)->toBe(1);
});

test('duplicate motorcycle serials are counted once using their most recently updated record', function () {
    $document = new CertificateDocument(['control_number' => 'DUPLICADO', 'total_serial_count' => 1]);
    $record = MsCertificado::factory()->create([
        'codigo' => 'DUPLICADO', 'niv' => '8YZC7MCC0TD000001',
        'status' => CertificateStatus::Dispatched, 'updated_at' => '2026-01-02',
    ]);
    MsCertificado::factory()->create([
        'codigo' => 'DUPLICADO', 'niv' => ' 8yzc7mcc0td000001 ',
        'status' => CertificateStatus::PendingDispatch, 'updated_at' => '2026-01-01',
    ]);
    $counts = app(LoadCertificateDocumentSerialCounts::class);
    $counts->handle(collect([$document]));

    expect($document->dispatched_serial_count)->toBe(1)
        ->and($document->pending_dispatch_serial_count)->toBe(0)
        ->and($document->returned_serial_count)->toBe(0);

    $record->update(['status' => CertificateStatus::Returned, 'updated_at' => '2026-01-03']);
    $counts->handle(collect([$document]));

    expect($document->dispatched_serial_count)->toBe(0)
        ->and($document->pending_dispatch_serial_count)->toBe(0)
        ->and($document->returned_serial_count)->toBe(1);
});

test('the certificate list follows finalized dispatches returns and redispatches without adding historical movements', function () {
    $user = User::factory()->create(['permissions' => [UserPermission::ViewCertificateDocuments->value]]);
    $document = CertificateDocument::query()->create([
        'control_number' => 'MOVIMIENTOS', 'file_name' => 'movimientos.pdf',
        'original_file_name' => 'movimientos.pdf', 'file_path' => 'movimientos.pdf',
        'total_serial_count' => 1,
    ]);
    $record = MsCertificado::factory()->create(['codigo' => $document->control_number]);
    $this->actingAs($user);
    $component = Livewire::test('certificate-document-list')
        ->assertSeeInOrder(['Seriales despachados', 'Seriales por despachar', 'Seriales devueltos'])
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->pending_dispatch_serial_count === 1);

    $dispatch = Dispatch::query()->create(['name' => 'Primer despacho']);
    $dispatch->lines()->create(['ms_certificado_id' => $record->id]);
    $component->call('$refresh')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->dispatched_serial_count === 0);
    app(FinalizeDispatch::class)->handle($dispatch);
    $component->call('$refresh')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->dispatched_serial_count === 1
            && $documents->sole()->pending_dispatch_serial_count === 0);

    $return = ProductReturn::factory()->create();
    $return->lines()->create(['ms_certificado_id' => $record->id]);
    app(FinalizeReturn::class)->handle($return);
    $component->call('$refresh')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->dispatched_serial_count === 0
            && $documents->sole()->returned_serial_count === 1);

    $redispatch = Dispatch::query()->create(['name' => 'Segundo despacho']);
    $redispatch->lines()->create(['ms_certificado_id' => $record->id]);
    app(FinalizeDispatch::class)->handle($redispatch);
    $component->call('$refresh')
        ->assertSet('documents', fn ($documents): bool => $documents->sole()->dispatched_serial_count === 1
            && $documents->sole()->returned_serial_count === 0
            && $documents->sole()->pending_dispatch_serial_count === 0);
});

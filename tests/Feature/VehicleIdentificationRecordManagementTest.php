<?php

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Actions\VehicleIdentificationRecords\ExportManagementCertificateAnalysis;
use App\Actions\VehicleIdentificationRecords\ImportManagementCertificateAnalysis;
use App\Actions\VehicleIdentificationRecords\HydrateManagementCertificateSourceData;
use App\Actions\VehicleIdentificationRecords\CompareRequestSerialsWithPdf;
use App\Models\MsCertificado;
use App\Models\CertificateDocument;
use App\Models\MotorcycleSerialRequest;
use App\Models\User;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\MotorcycleSerialRequestStatus;
use App\UserPermission;
use App\UserRole;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('finalizing a motorcycle serial request automatically creates one related management record', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($administrator);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->call('finalize')
        ->assertHasNoErrors();

    $management = VehicleIdentificationRecordManagement::query()->firstOrFail();

    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Done)
        ->and($management->motorcycleSerialRequest->is($serialRequest))->toBeTrue()
        ->and($serialRequest->vehicleIdentificationRecordManagement->is($management))->toBeTrue()
        ->and($management->status)->toBe(VehicleIdentificationRecordManagementStatus::Draft)
        ->and(VehicleIdentificationRecordManagement::query()
            ->where('motorcycle_serial_request_id', $serialRequest->id)
            ->count())->toBe(1);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSet('managementRecordId', $management->id)
        ->assertSee('Gestión de constancia relacionada')
        ->assertSee(route('vehicle_identification_record_management.edit', $management));
});

test('the management list shows generated records and has no manual creation action', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialRequest = MotorcycleSerialRequest::factory()->for($administrator)->create([
        'status' => MotorcycleSerialRequestStatus::Done,
    ]);
    $management = VehicleIdentificationRecordManagement::factory()
        ->for($serialRequest, 'motorcycleSerialRequest')
        ->create([
            'status' => VehicleIdentificationRecordManagementStatus::Draft,
            'created_at' => '2026-08-04 10:30:00',
        ]);

    $this->actingAs($administrator)
        ->get(route('vehicle_identification_record_management.index'))
        ->assertSuccessful()
        ->assertSee("#{$management->id}")
        ->assertSee("#{$serialRequest->id}")
        ->assertSee('04/08/2026 10:30')
        ->assertSee('Borrador')
        ->assertDontSee('Nuevo');

    $this->get('/gestion-constancia-registro-niv/nueva')->assertNotFound();
});

test('management records can be searched by source request', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $visibleRequest = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Done]);
    $hiddenRequest = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Done]);
    $visibleManagement = VehicleIdentificationRecordManagement::factory()
        ->for($visibleRequest, 'motorcycleSerialRequest')
        ->create();
    $hiddenManagement = VehicleIdentificationRecordManagement::factory()
        ->for($hiddenRequest, 'motorcycleSerialRequest')
        ->create();

    $this->actingAs($administrator);

    Livewire::test('vehicle-identification-record-management-list')
        ->set('search', (string) $visibleRequest->id)
        ->assertSee("#{$visibleManagement->id}")
        ->assertDontSee("#{$hiddenManagement->id}");
});

test('users with edit permission can update management status before importing', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewVehicleIdentificationRecordManagement->value,
            UserPermission::EditVehicleIdentificationRecordManagement->value,
        ],
    ]);
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'status' => VehicleIdentificationRecordManagementStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSee('Solicitud relacionada')
        ->assertSee('Fecha de creación')
        ->assertDontSee('Finalizar')
        ->call('setStatus', VehicleIdentificationRecordManagementStatus::InProgress->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($management->refresh()->status)->toBe(VehicleIdentificationRecordManagementStatus::InProgress);
});

test('view only users cannot change management status', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewVehicleIdentificationRecordManagement->value],
    ]);
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'status' => VehicleIdentificationRecordManagementStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSee('Solo lectura')
        ->assertDontSee('Finalizar')
        ->call('setStatus', VehicleIdentificationRecordManagementStatus::InProgress->value)
        ->assertForbidden();

    expect($management->refresh()->status)->toBe(VehicleIdentificationRecordManagementStatus::Draft);
});

test('done management records cannot return to an earlier status', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'status' => VehicleIdentificationRecordManagementStatus::Done,
    ]);

    $this->actingAs($administrator);

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSet('persistedDone', true)
        ->assertSee('no admite modificaciones')
        ->call('setStatus', VehicleIdentificationRecordManagementStatus::Draft->value)
        ->assertForbidden();
});

test('editing management permission automatically grants consultation access', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('permissions', [UserPermission::EditVehicleIdentificationRecordManagement->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->permissions)->toContain(
        UserPermission::ViewVehicleIdentificationRecordManagement->value,
        UserPermission::EditVehicleIdentificationRecordManagement->value,
    );
});

test('pdf serials are compared with the related request without using the certificate master', function () {
    $result = app(CompareRequestSerialsWithPdf::class)->handle(
        [
            '8YZC7MCC0TD000001',
            '8YZC7MCC0TD000002',
            '8YZC7MCC0TD000003',
            '8YZC7MCC0TD000004',
        ],
        [
            'controlNumber' => 'DG-NIV-RG5-0200-PC',
            'records' => [
                ['niv' => '8YZC7MCC0TD000001'],
                ['niv' => '8YZC7MCC0TD000002'],
                ['niv' => '8YZC7MCC0TD000002'],
                ['niv' => '8YZC7MCC0TD999999'],
            ],
            'invalidCount' => 1,
            'invalidRows' => [[
                'page' => 2,
                'niv' => 'NIV-INVALIDO',
                'reason' => 'El NIV debe tener 17 caracteres válidos.',
            ]],
        ],
    );

    expect($result['controlNumber'])->toBe('DG-NIV-RG5-0200-PC')
        ->and($result['expectedCount'])->toBe(4)
        ->and($result['pdfOccurrenceCount'])->toBe(4)
        ->and($result['matchedSerials'])->toBe([
            '8YZC7MCC0TD000001',
            '8YZC7MCC0TD000002',
        ])
        ->and($result['duplicateSerials'])->toBe([[
            'serial' => '8YZC7MCC0TD000002',
            'occurrences' => 2,
            'requested' => true,
        ]])
        ->and($result['unexpectedSerials'])->toBe([[
            'serial' => '8YZC7MCC0TD999999',
            'occurrences' => 1,
        ]])
        ->and($result['missingSerials'])->toBe([
            '8YZC7MCC0TD000003',
            '8YZC7MCC0TD000004',
        ])
        ->and($result['invalidRows'])->toHaveCount(1)
        ->and($result['exactMatch'])->toBeFalse();
});

test('a pdf with every requested serial exactly once is an exact match', function () {
    $result = app(CompareRequestSerialsWithPdf::class)->handle(
        ['8YZC7MCC0TD000001', '8YZC7MCC0TD000002'],
        [
            'controlNumber' => 'DG-NIV-RG5-0201-PC',
            'records' => [
                ['niv' => '8yzc7mcc0td000002'],
                ['niv' => '8YZC7MCC0TD000001'],
            ],
            'invalidCount' => 0,
            'invalidRows' => [],
        ],
    );

    expect($result['matchedSerials'])->toHaveCount(2)
        ->and($result['duplicateSerials'])->toBeEmpty()
        ->and($result['unexpectedSerials'])->toBeEmpty()
        ->and($result['missingSerials'])->toBeEmpty()
        ->and($result['invalidRows'])->toBeEmpty()
        ->and($result['exactMatch'])->toBeTrue();
});

test('authorized users can analyze and retain multiple certificates from the management form', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewVehicleIdentificationRecordManagement->value,
            UserPermission::EditVehicleIdentificationRecordManagement->value,
        ],
    ]);
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'status' => VehicleIdentificationRecordManagementStatus::Draft,
    ]);
    $line = $management->motorcycleSerialRequest->lines()->firstOrFail();
    $line->serialEntries()->delete();
    $line->serialEntries()->createMany([
        ['serial' => '8YZC7MCC0TD000001'],
        ['serial' => '8YZC7MCC0TD000002'],
        ['serial' => '8YZC7MCC0TD000003'],
        ['serial' => '8YZC7MCC0TD000004'],
    ]);
    $extractor = Mockery::mock(ImportCertificatesFromPdf::class);
    $extractor->shouldReceive('parseForComparison')->twice()->andReturn(
        [
            'controlNumber' => 'DG-NIV-RG5-0202-PC',
            'records' => [
                ['niv' => '8YZC7MCC0TD000001'],
                ['niv' => '8YZC7MCC0TD000002'],
                ['niv' => '8YZC7MCC0TD000002'],
            ],
            'invalidCount' => 0,
            'invalidRows' => [],
        ],
        [
            'controlNumber' => 'DG-NIV-RG5-0203-PC',
            'records' => [
                ['niv' => '8YZC7MCC0TD000003'],
                ['niv' => '8YZC7MCC0TD000002'],
                ['niv' => '8YZC7MCC0TD999999'],
            ],
            'invalidCount' => 1,
            'invalidRows' => [[
                'niv' => 'NIV-INVALIDO',
                'reason' => 'El NIV debe tener 17 caracteres válidos.',
            ]],
        ],
    );
    app()->instance(ImportCertificatesFromPdf::class, $extractor);
    $firstPdf = UploadedFile::fake()->createWithContent(
        'constancia-uno.pdf',
        "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
    );
    $secondPdf = UploadedFile::fake()->createWithContent(
        'constancia-dos.pdf',
        "%PDF-1.4\n2 0 obj\n<<>>\nendobj\n%%EOF",
    );

    $this->actingAs($user);

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSee('Seleccionar PDF')
        ->set('pdfFiles', [$firstPdf, $secondPdf])
        ->call('analyzePdf')
        ->assertHasNoErrors()
        ->assertSet('showPdfAnalysis', true)
        ->assertCount('certificates', 2)
        ->assertSet('matchedSerials.0.serial', '8YZC7MCC0TD000001')
        ->assertSet('matchedSerials.0.certificate', 'DG-NIV-RG5-0202-PC')
        ->assertSet('matchedSerials.2.serial', '8YZC7MCC0TD000003')
        ->assertSet('matchedSerials.2.certificate', 'DG-NIV-RG5-0203-PC')
        ->assertSet('unexpectedSerials.0.serial', '8YZC7MCC0TD999999')
        ->assertSet('missingSerials.0.serial', '8YZC7MCC0TD000004')
        ->assertSee('Resultado de la comparación')
        ->assertSee('DG-NIV-RG5-0202-PC')
        ->assertSee('DG-NIV-RG5-0203-PC')
        ->assertSee('Seriales certificados que no estaban en la solicitud')
        ->assertSee('Seriales de la solicitud que quedaron sin certificar')
        ->call('cancelPdfAnalysis')
        ->assertSet('showPdfAnalysis', false)
        ->assertSee('Ver análisis')
        ->call('openPdfAnalysis')
        ->assertSet('showPdfAnalysis', true)
        ->assertSee('Resultado de la comparación');

    $certificates = VehicleIdentificationRecordManagementCertificate::query()
        ->whereBelongsTo($management, 'management')
        ->orderBy('id')
        ->get();

    expect($certificates)->toHaveCount(2)
        ->and($certificates->pluck('control_number')->all())->toBe([
            'DG-NIV-RG5-0202-PC',
            'DG-NIV-RG5-0203-PC',
        ])
        ->and(VehicleIdentificationRecordCertificateSerial::query()
            ->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Certified)
            ->count())->toBe(3)
        ->and(VehicleIdentificationRecordCertificateSerial::query()
            ->where('classification', VehicleIdentificationRecordCertificateSerialClassification::Duplicate)
            ->count())->toBe(2);

    foreach ($certificates as $certificate) {
        Storage::disk('local')->assertExists($certificate->file_path);
    }

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSet('showPdfAnalysis', false)
        ->assertCount('certificates', 2)
        ->assertSee('DG-NIV-RG5-0202-PC')
        ->assertSee('Certificados')
        ->assertSee('Sin certificar')
        ->assertDontSee('Seriales certificados que no estaban en la solicitud')
        ->call('openPdfAnalysis')
        ->assertSet('showPdfAnalysis', true)
        ->assertSee('Seriales certificados que no estaban en la solicitud')
        ->call('downloadCertificate', $certificates->first()->id)
        ->assertFileDownloaded('constancia-uno.pdf');

    $removedCertificate = $certificates->first();

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->call('openDeleteCertificateConfirmation', $removedCertificate->id)
        ->assertSet('showDeleteCertificateConfirmation', true)
        ->assertSee('¿Quitar este certificado?')
        ->call('deleteCertificate')
        ->assertHasNoErrors()
        ->assertSet('showDeleteCertificateConfirmation', false)
        ->assertCount('certificates', 1)
        ->assertSet('matchedSerials.1.serial', '8YZC7MCC0TD000002')
        ->assertSet('matchedSerials.1.certificate', 'DG-NIV-RG5-0203-PC');

    $this->assertModelMissing($removedCertificate);
    Storage::disk('local')->assertMissing($removedCertificate->file_path);
});

test('certificates are accumulated through independent requests', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewVehicleIdentificationRecordManagement->value,
            UserPermission::EditVehicleIdentificationRecordManagement->value,
        ],
    ]);
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $line = $management->motorcycleSerialRequest->lines()->firstOrFail();
    $line->serialEntries()->delete();
    $line->serialEntries()->createMany([
        ['serial' => '8YZC7MCC0TD000001'],
        ['serial' => '8YZC7MCC0TD000002'],
    ]);
    $extractor = Mockery::mock(ImportCertificatesFromPdf::class);
    $extractor->shouldReceive('parseForComparison')->twice()->andReturn(
        [
            'controlNumber' => 'DG-NIV-RG5-0301-PC',
            'records' => [['niv' => '8YZC7MCC0TD000001']],
            'invalidCount' => 0,
            'invalidRows' => [],
        ],
        [
            'controlNumber' => 'DG-NIV-RG5-0302-PC',
            'records' => [['niv' => '8YZC7MCC0TD000002']],
            'invalidCount' => 0,
            'invalidRows' => [],
        ],
    );
    app()->instance(ImportCertificatesFromPdf::class, $extractor);

    $this->actingAs($user);

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->set('pdfFiles', [UploadedFile::fake()->createWithContent('primero.pdf', '%PDF-first')])
        ->assertSee('Progreso del análisis de certificados')
        ->assertSee('Finalizando extracción del certificado')
        ->call('processNextPdf')
        ->assertHasNoErrors();

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->set('pdfFiles', [UploadedFile::fake()->createWithContent('segundo.pdf', '%PDF-second')])
        ->call('processNextPdf')
        ->assertHasNoErrors()
        ->assertSet('exactPdfMatch', true)
        ->assertSee('DG-NIV-RG5-0301-PC')
        ->assertSee('DG-NIV-RG5-0302-PC');

    expect($management->certificates()->count())->toBe(2);
});

test('certificate analysis exports and imports only selectable categories', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $certificate = $management->certificates()->create([
        'control_number' => 'DG-NIV-RG5-0401-PC',
        'original_file_name' => 'certificado.pdf',
        'file_path' => 'certificates/certificado.pdf',
        'file_hash' => hash('sha256', 'certificado'),
        'valid_occurrence_count' => 4,
        'invalid_count' => 1,
        'analyzed_at' => now(),
    ]);
    Storage::disk('local')->put($certificate->file_path, '%PDF-1.4 certificado');
    $validSource = [
        'no' => '1',
        'marca' => 'BERA',
        'modelo' => 'BR 150 BRF',
        'tipo' => 'MOTOCICLETA',
        'fabricacion' => '2026',
        'anio' => 2026,
        'niv' => '8YZC7MCC0TD000001',
        'codigo' => 'DG-NIV-RG5-0401-PC',
    ];
    $certificate->serialResults()->createMany([
        [
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::Certified,
            'serial' => '8YZC7MCC0TD000001',
            'occurrences' => 1,
            'source_data' => $validSource,
        ],
        [
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
            'serial' => '8YZC7MCC0TD000002',
            'occurrences' => 2,
            'source_data' => [...$validSource, 'no' => '2', 'niv' => '8YZC7MCC0TD000002'],
            'reason' => 'El serial está repetido.',
        ],
        [
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::Invalid,
            'serial' => 'NIV-INVALIDO',
            'occurrences' => 1,
            'source_data' => [
                'page' => 2,
                'values' => ['3', 'BERA', 'BR 150 BRF', 'MOTOCICLETA', '2026', '2026', 'NIV-INVALIDO'],
            ],
            'reason' => 'El NIV es inválido.',
        ],
        [
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::Unexpected,
            'serial' => '8YZC7MCC0TD999999',
            'occurrences' => 1,
            'source_data' => [...$validSource, 'no' => '4', 'niv' => '8YZC7MCC0TD999999', '_requested' => false],
        ],
        [
            'classification' => VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
            'serial' => '8YZC7MCC0TD999999',
            'occurrences' => 1,
            'source_data' => [...$validSource, 'no' => '4', 'niv' => '8YZC7MCC0TD999999', '_requested' => false],
            'reason' => 'El serial no solicitado está repetido dentro del PDF.',
        ],
    ]);

    $this->actingAs($administrator);

    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSee('Exportar')
        ->assertSee('Incluir al importar')
        ->assertSeeHtml('wire:model.live="includeCertified"')
        ->assertSeeHtml('wire:model.live="includeDuplicates"')
        ->assertSeeHtml('wire:model.live="includeUnexpected"')
        ->assertSeeHtml('wire:model.live="includeMissing"')
        ->assertSeeHtml('wire:model.live="includeInvalid"')
        ->assertSee('Importando registros al maestro')
        ->assertSee('Progreso de la importación al Maestro de Seriales Certificados')
        ->set('includeCertified', true)
        ->set('includeDuplicates', true)
        ->set('includeInvalid', true)
        ->call('importCertificateSelection')
        ->assertHasNoErrors()
        ->assertSee('Se importaron 4 registros al maestro')
        ->assertSet('persistedDone', true)
        ->assertSet('status', VehicleIdentificationRecordManagementStatus::Done->value)
        ->assertDontSee('Importar seleccionados');

    expect(MsCertificado::query()->count())->toBe(4)
        ->and(MsCertificado::query()->where('niv', '8YZC7MCC0TD999999')->exists())->toBeFalse()
        ->and($management->refresh()->status)->toBe(VehicleIdentificationRecordManagementStatus::Done)
        ->and(CertificateDocument::query()->count())->toBe(1)
        ->and(CertificateDocument::query()->sole()->managements()->whereKey($management->id)->exists())->toBeTrue();

    $export = app(ExportManagementCertificateAnalysis::class)->handle($management, 'certified');

    expect($export->getFile()->isFile())->toBeTrue()
        ->and($export->getFile()->getFilename())->toEndWith('.xlsx');

    $secondImport = app(ImportManagementCertificateAnalysis::class)->handle($management, true, true, true);

    expect($secondImport['imported'])->toBe(0)
        ->and(MsCertificado::query()->count())->toBe(4)
        ->and(CertificateDocument::query()->count())->toBe(1);
});

test('unexpected and missing serials can be exported and optionally imported', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $missingNivs = $management->motorcycleSerialRequest->lines()
        ->with('serialEntries:id,motorcycle_serial_request_line_id,serial')
        ->get()
        ->flatMap->serialEntries
        ->pluck('serial')
        ->unique()
        ->values();
    $certificate = $management->certificates()->create([
        'control_number' => 'DG-NIV-RG5-0601-PC',
        'original_file_name' => 'adicionales.pdf',
        'file_path' => 'certificates/adicionales.pdf',
        'file_hash' => hash('sha256', 'adicionales'),
        'valid_occurrence_count' => 1,
        'invalid_count' => 0,
        'analyzed_at' => now(),
    ]);
    Storage::disk('local')->put($certificate->file_path, '%PDF-1.4 adicionales');
    $unexpectedNiv = '8YZC7MCC0TD888888';
    $certificate->serialResults()->create([
        'classification' => VehicleIdentificationRecordCertificateSerialClassification::Unexpected,
        'serial' => $unexpectedNiv,
        'occurrences' => 1,
        'source_data' => [
            'no' => '99',
            'marca' => 'BERA',
            'modelo' => 'BR 150 BRF',
            'tipo' => 'MOTOCICLETA',
            'fabricacion' => '2026',
            'anio' => 2026,
            'niv' => $unexpectedNiv,
            'codigo' => 'DG-NIV-RG5-0601-PC',
            '_requested' => false,
        ],
    ]);

    $this->actingAs($administrator);

    $unexpectedExport = app(ExportManagementCertificateAnalysis::class)->handle($management, 'unexpected');
    $missingExport = app(ExportManagementCertificateAnalysis::class)->handle($management, 'missing');

    expect($unexpectedExport->getFile()->isFile())->toBeTrue()
        ->and($missingExport->getFile()->isFile())->toBeTrue();

    $result = app(ImportManagementCertificateAnalysis::class)
        ->handle($management, false, false, false, true, true);

    expect($result['unexpected'])->toBe(1)
        ->and($result['missing'])->toBe($missingNivs->count())
        ->and($result['imported'])->toBe(1 + $missingNivs->count())
        ->and(MsCertificado::query()->where('niv', $unexpectedNiv)->where('codigo', 'DG-NIV-RG5-0601-PC')->exists())->toBeTrue();

    foreach ($missingNivs as $missingNiv) {
        expect(MsCertificado::query()->where('niv', $missingNiv)->where('codigo', '')->exists())->toBeTrue();
    }
});

test('legacy certificate source data is recovered from its stored pdf', function () {
    Storage::fake('local');
    $management = VehicleIdentificationRecordManagement::factory()->create();
    $certificate = $management->certificates()->create([
        'control_number' => 'DG-NIV-RG5-0501-PC',
        'original_file_name' => 'anterior.pdf',
        'file_path' => 'certificates/anterior.pdf',
        'file_hash' => hash('sha256', 'anterior'),
        'valid_occurrence_count' => 1,
        'invalid_count' => 0,
        'analyzed_at' => now(),
    ]);
    Storage::disk('local')->put($certificate->file_path, '%PDF-legacy');
    $result = $certificate->serialResults()->create([
        'classification' => VehicleIdentificationRecordCertificateSerialClassification::Certified,
        'serial' => '8YZC7MCC0TD000001',
        'occurrences' => 1,
    ]);
    $extractor = Mockery::mock(ImportCertificatesFromPdf::class);
    $extractor->shouldReceive('parseForComparison')->once()->andReturn([
        'controlNumber' => 'DG-NIV-RG5-0501-PC',
        'records' => [[
            'no' => '1',
            'marca' => 'BERA',
            'modelo' => 'BR 150 BRF',
            'tipo' => 'MOTOCICLETA',
            'fabricacion' => '2026',
            'anio' => 2026,
            'niv' => '8YZC7MCC0TD000001',
            'codigo' => 'DG-NIV-RG5-0501-PC',
        ]],
        'invalidCount' => 0,
        'invalidRows' => [],
    ]);

    app(HydrateManagementCertificateSourceData::class, ['extractor' => $extractor])->handle($management);

    expect($result->refresh()->source_data)
        ->toMatchArray([
            'no' => '1',
            'marca' => 'BERA',
            'niv' => '8YZC7MCC0TD000001',
            '_requested' => true,
        ]);
});

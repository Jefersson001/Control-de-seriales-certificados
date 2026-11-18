<?php

use App\Actions\Certificates\ImportCertificates;
use App\Actions\Dispatches\FinalizeDispatch;
use App\Actions\Products\ImportProducts;
use App\Actions\Returns\FinalizeReturn;
use App\Actions\VehicleIdentificationRecords\ImportManagementCertificateAnalysis;
use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MotorcycleSerialRequest;
use App\Models\MsCertificado;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\MotorcycleSerialRequestStatus;
use App\UserRole;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

test('dispatches identify uncertified products for each brand and keep the existing exception workflow', function (string $brand, string $category, string $code, string $niv) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['brand' => $brand, 'category' => $category, 'niv' => $code]);
    Product::factory()->create(['brand' => $brand, 'category' => $category === 'nacional' ? 'importados' : 'nacional', 'niv' => $code]);
    Livewire::test('dispatch-form')->set('importPdf', guardrailDispatchPdf($niv))->call('importDispatchPdf')->assertHasNoErrors()
        ->assertSet('uncertifiedRecords.0.product_id', $product->id)->assertSet('uncertifiedRecords.0.product_niv', $code)
        ->call('openFinalizeConfirmation')->assertSee('1 sin certificar')->call('finalize')->assertHasNoErrors();
    expect(Dispatch::query()->sole()->status)->toBe(DispatchStatus::Done);
})->with(guardrailMotorcycles());

test('a previously dispatched uncertified NIV cannot be finalized again', function () {
    $first = Dispatch::query()->create(['name' => 'PRIMERO', 'status' => DispatchStatus::Done]);
    $first->uncertifiedLines()->create(['niv' => 'HL4PKWAB3VZ000001']);
    $second = Dispatch::query()->create(['name' => 'SEGUNDO']);
    $second->uncertifiedLines()->create(['niv' => 'HL4PKWAB3VZ000001']);
    expect(fn () => app(FinalizeDispatch::class)->handle($second))->toThrow(RuntimeException::class, 'devolución');
    expect($second->refresh()->status)->toBe(DispatchStatus::Draft);
});

test('reloading a dispatch PDF upgrades a serial that has since been certified', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $niv = '8YZC7MCC3VD000001';
    $component = Livewire::test('dispatch-form')->set('importPdf', guardrailDispatchPdf($niv))->call('importDispatchPdf')
        ->assertCount('uncertifiedRecords', 1);
    $certificate = MsCertificado::factory()->create(['niv' => $niv, 'marca' => 'BERA']);
    $component->call('importDispatchPdf')->assertHasNoErrors()->assertCount('uncertifiedRecords', 0)
        ->assertSet('selectedIds', [$certificate->id])->call('finalize')->assertHasNoErrors();
    expect($certificate->refresh()->status)->toBe(CertificateStatus::Dispatched);
});

test('changed product codes are rechecked before an uncertified serial is dispatched', function () {
    $product = Product::factory()->create(['brand' => 'morini', 'category' => 'importados', 'niv' => 'HL4PMVBB']);
    $dispatch = Dispatch::query()->create(['name' => 'PRODUCTO-MODIFICADO']);
    $dispatch->uncertifiedLines()->create(['niv' => 'HL4PKWAB3VZ000001', 'product_id' => $product->id]);
    expect(fn () => app(FinalizeDispatch::class)->handle($dispatch))->toThrow(RuntimeException::class, 'no coincide');
});

test('incomplete master records stay pending when dispatch finalization is attempted', function (string $field) {
    $certificate = MsCertificado::factory()->create([$field => '']);
    $dispatch = Dispatch::query()->create(['name' => 'INCOMPLETO']);
    $dispatch->lines()->create(['ms_certificado_id' => $certificate->id]);
    expect(fn () => app(FinalizeDispatch::class)->handle($dispatch))->toThrow(RuntimeException::class, 'incompleta');
    expect($certificate->refresh()->status)->toBe(CertificateStatus::PendingDispatch)
        ->and($dispatch->refresh()->status)->toBe(DispatchStatus::Draft);
})->with(['marca', 'modelo', 'codigo']);

test('a second master record cannot bypass an already dispatched NIV', function () {
    $first = MsCertificado::factory()->create(['niv' => '8YZC7MCC3VD000001', 'status' => CertificateStatus::Dispatched]);
    $duplicate = MsCertificado::factory()->create(['niv' => $first->niv]);
    $dispatch = Dispatch::query()->create(['name' => 'DUPLICADO']);
    $dispatch->lines()->create(['ms_certificado_id' => $duplicate->id]);
    expect(fn () => app(FinalizeDispatch::class)->handle($dispatch))->toThrow(RuntimeException::class, 'ya está despachado');
    expect($duplicate->refresh()->status)->toBe(CertificateStatus::PendingDispatch);
});

test('two different master IDs for the same NIV cannot be dispatched together', function () {
    $records = MsCertificado::factory()->count(2)->create(['niv' => '8YZC7MCC3VD000001']);
    $dispatch = Dispatch::query()->create(['name' => 'DOS-IDS']);
    foreach ($records as $record) {
        $dispatch->lines()->create(['ms_certificado_id' => $record->id]);
    }
    expect(fn () => app(FinalizeDispatch::class)->handle($dispatch))->toThrow(RuntimeException::class, 'más de una vez');
});

test('a recorded return permits the existing redispatch workflow', function () {
    $certificate = MsCertificado::factory()->create();
    $first = Dispatch::query()->create(['name' => 'IDA']);
    $first->lines()->create(['ms_certificado_id' => $certificate->id]);
    app(FinalizeDispatch::class)->handle($first);
    $return = ProductReturn::factory()->create();
    $return->lines()->create(['ms_certificado_id' => $certificate->id]);
    app(FinalizeReturn::class)->handle($return);
    $second = Dispatch::query()->create(['name' => 'REENVIO']);
    $second->lines()->create(['ms_certificado_id' => $certificate->id]);
    app(FinalizeDispatch::class)->handle($second);
    expect($second->refresh()->status)->toBe(DispatchStatus::Done)->and($certificate->refresh()->status)->toBe(CertificateStatus::Dispatched);
});

test('a stale dispatch form cannot overwrite a dispatch finalized by another session', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $certificate = MsCertificado::factory()->create();
    $dispatch = Dispatch::query()->create(['name' => 'ORIGINAL']);
    $dispatch->lines()->create(['ms_certificado_id' => $certificate->id]);
    $component = Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id]);
    $dispatch->update(['status' => DispatchStatus::Done]);
    $component->set('name', 'ALTERADO')->call('save')->assertHasErrors('selectedIds')->assertSee('El despacho ya fue finalizado desde otra sesión.');
    expect($dispatch->refresh()->name)->toBe('ORIGINAL');
});

test('completed dispatch history cannot be deleted', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $dispatch = Dispatch::query()->create(['name' => 'HISTORICO', 'status' => DispatchStatus::Done]);
    Livewire::test('dispatch-list')->call('openDeleteConfirmation', $dispatch->id)->call('deleteDispatch')->assertHasErrors('deleteDispatch');
    $this->assertModelExists($dispatch);
});

test('manual request drafts remain editable and invalid data is blocked only when finalizing', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['brand' => 'morini', 'category' => 'importados', 'niv' => 'HL4PKWAB']);
    $component = Livewire::test('motorcycle-serial-request-form')->set('category', 'importados')->set('lines.0.product_id', $product->id)
        ->set('lines.0.quantity', 2)->set('lines.0.serials', 'INCOMPLETO')->call('save')->assertHasNoErrors();
    $request = MotorcycleSerialRequest::query()->sole();
    Livewire::test('motorcycle-serial-request-form', ['requestId' => $request->id])->call('finalize')
        ->assertHasErrors(['lines.0.quantity', 'lines.0.serials']);
    expect($request->refresh()->status)->toBe(MotorcycleSerialRequestStatus::InProgress);
});

test('valid requests proceed through certification master import and dispatch for every motorcycle brand', function (string $brand, string $category, string $code, string $niv) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['brand' => $brand, 'category' => $category, 'niv' => $code]);
    Livewire::test('motorcycle-serial-request-form')->set('category', $category)->set('lines.0.product_id', $product->id)
        ->set('lines.0.quantity', 1)->set('lines.0.serials', $niv)->call('save')->assertHasNoErrors();
    $request = MotorcycleSerialRequest::query()->sole();
    Livewire::test('motorcycle-serial-request-form', ['requestId' => $request->id])->call('finalize')->assertHasNoErrors();
    $request->refresh();
    expect($request->status)->toBe(MotorcycleSerialRequestStatus::Done)->and($request->vehicleIdentificationRecordManagement->category)->toBe($category);
    $management = $request->vehicleIdentificationRecordManagement;
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create(['management_id' => $management->id, 'valid_occurrence_count' => 1]);
    $documentUpload = guardrailDispatchPdf($niv);
    Storage::disk('local')->put($certificate->file_path, file_get_contents($documentUpload->getRealPath()));
    VehicleIdentificationRecordCertificateSerial::factory()->create([
        'certificate_id' => $certificate->id,
        'request_serial_id' => $request->lines()->first()->serialEntries()->first()->id,
        'classification' => VehicleIdentificationRecordCertificateSerialClassification::Certified,
        'serial' => $niv,
        'source_data' => ['no' => '1', 'marca' => strtoupper($brand), 'modelo' => $product->name, 'tipo' => 'MOTOCICLETA', 'fabricacion' => '2026', 'anio' => 2027, 'niv' => $niv, 'codigo' => $certificate->control_number],
    ]);
    $result = app(ImportManagementCertificateAnalysis::class)->handle($management, true, false, false);
    expect($result['imported'])->toBe(1);
    $master = MsCertificado::query()->sole();
    expect($master->category)->toBe($category);
    $dispatch = Dispatch::query()->create(['name' => 'FLUJO-'.$brand]);
    $dispatch->lines()->create(['ms_certificado_id' => $master->id]);
    app(FinalizeDispatch::class)->handle($dispatch);
    expect($dispatch->refresh()->status)->toBe(DispatchStatus::Done)->and($master->refresh()->status)->toBe(CertificateStatus::Dispatched);
})->with(guardrailMotorcycles());

test('new Bera codes are validated while unchanged legacy codes remain editable', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test('product-manager', ['formOnly' => true])->set('description', 'NUEVO')->set('niv', '2026')->call('save')->assertHasErrors('niv');
    $legacy = Product::factory()->create(['brand' => 'bera', 'niv' => '2026']);
    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $legacy->id])->assertSee('Revisa este código histórico')
        ->set('description', 'HISTORICO EDITADO')->call('save')->assertHasNoErrors();
    expect($legacy->refresh()->niv)->toBe('2026');
    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $legacy->id])->set('niv', 'C7')->call('save')->assertHasNoErrors();
    expect($legacy->refresh()->niv)->toBe('C7');
});

test('direct certificate spreadsheet imports preserve imported and national categories', function () {
    Storage::fake('local');
    $path = guardrailWorkbook([
        ['NO', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV', 'Código'],
        ['1', 'BERA', 'BR 150', 'MOTO', '2026', '2027', '8YZC7MCC3VD000001', 'C1'],
        ['2', 'ZONTES', '150 X', 'MOTO', '2026', '2027', 'LD3THK5BXV1000001', 'C2'],
        ['3', 'MORINI', 'CALIBRO', 'MOTO', '2026', '2027', 'HL4PKWAB3VZ000001', 'C3'],
    ]);
    expect(app(ImportCertificates::class)->handle($path)['imported'])->toBe(3);
    expect(MsCertificado::query()->where('category', 'importados')->count())->toBe(2)
        ->and(MsCertificado::query()->where('category', 'nacional')->count())->toBe(1);
});

test('a certificate with a conflicting motorcycle brand cannot be dispatched', function () {
    $certificate = MsCertificado::factory()->create(['marca' => 'MORINI', 'niv' => 'LD3THK5BXV1000001']);
    $dispatch = Dispatch::query()->create(['name' => 'MARCA-INCORRECTA']);
    $dispatch->lines()->create(['ms_certificado_id' => $certificate->id]);
    expect(fn () => app(FinalizeDispatch::class)->handle($dispatch))->toThrow(RuntimeException::class, 'no coincide');
    expect($certificate->refresh()->status)->toBe(CertificateStatus::PendingDispatch);
});

test('a correctly sized NIV for another model is rejected when finalizing a manual request', function (string $brand, string $category, string $code, string $niv) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['brand' => $brand, 'category' => $category, 'niv' => $code]);
    Livewire::test('motorcycle-serial-request-form')->set('category', $category)->set('lines.0.product_id', $product->id)
        ->set('lines.0.quantity', 1)->set('lines.0.serials', $niv)->call('save')->assertHasNoErrors();
    $request = MotorcycleSerialRequest::query()->sole();
    Livewire::test('motorcycle-serial-request-form', ['requestId' => $request->id])->call('finalize')->assertHasErrors('lines.0.serials');
    expect($request->refresh()->status)->toBe(MotorcycleSerialRequestStatus::InProgress);
})->with([
    ['bera', 'nacional', 'C7', '8YZWJMCA7VD000001'],
    ['zontes', 'importados', 'LD3THK5B', 'LD3THR6B0V1000001'],
    ['morini', 'importados', 'HL4PKWAB', 'HL4PMVBBXVZ000001'],
]);

test('a corrected draft can be finalized without keeping old validation errors', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['brand' => 'bera', 'category' => 'nacional', 'niv' => 'C7']);
    Livewire::test('motorcycle-serial-request-form')->set('lines.0.product_id', $product->id)->set('lines.0.quantity', 2)
        ->set('lines.0.serials', 'INCOMPLETO')->call('save')->assertHasNoErrors();
    $request = MotorcycleSerialRequest::query()->sole();
    Livewire::test('motorcycle-serial-request-form', ['requestId' => $request->id])->call('finalize')->assertHasErrors()
        ->set('lines.0.quantity', 1)->set('lines.0.serials', '8YZC7MCC3VD000001')->call('finalize')->assertHasNoErrors();
    expect($request->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Done);
});

test('product spreadsheets skip malformed codes and detect imported brands', function () {
    Storage::fake('local');
    $path = guardrailWorkbook([
        ['Descripción', '1ero', '2do', 'NIV', 'Año'],
        ['CORRECTO', 'C', '7', 'C7', '2027'], ['INCORRECTO', 'C', '7', '2026', '2027'],
        ['ZONTES', 'LD3', 'THK5B', 'LD3THK5B', '2027'], ['MORINI', 'HL4', 'PKWAB', 'HL4PKWAB', '2027'],
    ]);
    $result = app(ImportProducts::class)->handle($path);
    expect($result)->imported->toBe(3)->skipped->toBe(1);
    expect(Product::query()->where('category', 'importados')->count())->toBe(2);
});

/** @return list<array{string, string, string, string}> */
function guardrailMotorcycles(): array
{
    return [['bera', 'nacional', 'C7', '8YZC7MCC3VD000001'], ['zontes', 'importados', 'LD3THK5B', 'LD3THK5BXV1000001'], ['morini', 'importados', 'HL4PKWAB', 'HL4PKWAB3VZ000001']];
}

function guardrailDispatchPdf(string $niv): UploadedFile
{
    $pdf = new FPDF;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, 'PLM-PT-LM/OUT/99999 '.$niv);

    return UploadedFile::fake()->createWithContent('Despacho.pdf', $pdf->Output('S'));
}

/** @param list<list<string>> $rows */
function guardrailWorkbook(array $rows): string
{
    $path = Storage::disk('local')->path(Str::uuid().'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

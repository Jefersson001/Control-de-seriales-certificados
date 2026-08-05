<?php

use App\Models\MotorcycleSerialRequest;
use App\Models\MotorcycleSerialRequestLineSerial;
use App\Models\Product;
use App\Actions\MotorcycleSerialRequests\ExtractSerialsFromWorkbook;
use App\Models\User;
use App\MotorcycleSerialRequestStatus;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

test('administrators see the request list and new button', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('motorcycle_serial_requests.index'))
        ->assertSuccessful()
        ->assertSee('Fecha')
        ->assertDontSee('Actualización')
        ->assertSee('Buscar por número, producto o usuario')
        ->assertSee('Nuevo')
        ->assertSee(route('motorcycle_serial_requests.create'));
});

test('requests can be searched by product in real time', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $visibleProduct = Product::factory()->create(['name' => 'Moto urbana']);
    $hiddenProduct = Product::factory()->create(['name' => 'Moto deportiva']);
    $visibleRequest = MotorcycleSerialRequest::factory()->create([
        'request_date' => '2026-08-03',
    ]);
    $visibleRequest->lines()->first()->update(['product_id' => $visibleProduct->id]);
    MotorcycleSerialRequest::factory()->create()->lines()->first()->update(['product_id' => $hiddenProduct->id]);

    $this->actingAs($administrator);

    Livewire::test('motorcycle-serial-request-list')
        ->set('search', 'urbana')
        ->assertSee('03/08/2026')
        ->assertSee('Moto urbana')
        ->assertDontSee('Moto deportiva');
});

test('authorized users can create a draft request related to a product', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);
    $product = Product::factory()->create(['name' => 'Moto de trabajo']);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->assertSet('status', MotorcycleSerialRequestStatus::Draft->value)
        ->assertSet('lines.0.quantity', 1)
        ->assertSet('requestDate', now()->toDateString())
        ->assertDontSee('Usuario creador')
        ->assertSee('Fecha')
        ->set('requestDate', '2026-08-03')
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.quantity', 25)
        ->set('lines.0.serials', "SERIAL-001\nSERIAL-002")
        ->call('save')
        ->assertHasNoErrors();

    $serialRequest = MotorcycleSerialRequest::query()->firstOrFail();

    expect($serialRequest->lines()->first()->product->is($product))->toBeTrue()
        ->and($serialRequest->user->is($user))->toBeTrue()
        ->and($serialRequest->request_date?->toDateString())->toBe('2026-08-03')
        ->and($serialRequest->lines()->first()->quantity)->toBe(25)
        ->and($serialRequest->status)->toBe(MotorcycleSerialRequestStatus::Draft);
});

test('the creator of an existing request cannot be changed while editing', function () {
    $creator = User::factory()->create();
    $editor = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->for($creator)->create();

    $this->actingAs($editor);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertDontSee('Usuario creador')
        ->call('save')
        ->assertHasNoErrors();

    expect($serialRequest->refresh()->user->is($creator))->toBeTrue();
});

test('selecting a request opens its form', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewMotorcycleSerialRequests->value],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user)
        ->get(route('motorcycle_serial_requests.index'))
        ->assertSuccessful()
        ->assertSee(route('motorcycle_serial_requests.edit', $serialRequest));

    $this->actingAs($user)
        ->get(route('motorcycle_serial_requests.edit', $serialRequest))
        ->assertSuccessful()
        ->assertSee('Solicitud #'.$serialRequest->id)
        ->assertSee('Solo lectura');
});

test('authorized users can edit the product and finalize the request', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
        ],
    ]);
    $originalProduct = Product::factory()->create();
    $newProduct = Product::factory()->create();
    $serialRequest = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Draft]);
    $serialRequest->lines()->first()->update(['product_id' => $originalProduct->id]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->set('lines.0.product_id', $newProduct->id)
        ->set('lines.0.quantity', 40)
        ->set('lines.0.serials', 'SERIAL-NUEVO')
        ->assertSee('Finalizar')
        ->call('openFinalizeConfirmation')
        ->assertSet('showFinalizeConfirmation', true)
        ->assertSee('¿Finalizar esta solicitud?')
        ->assertSee('Sí, finalizar solicitud')
        ->call('finalize')
        ->assertHasNoErrors();

    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Done)
        ->and($serialRequest->lines()->first()->product_id)->toBe($newProduct->id)
        ->and($serialRequest->lines()->first()->quantity)->toBe(40);
});

test('request quantity must be a positive integer', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);
    $product = Product::factory()->create();

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.quantity', 0)
        ->set('lines.0.serials', 'SERIAL-001')
        ->call('save')
        ->assertHasErrors(['lines.0.quantity' => 'min']);

    expect(MotorcycleSerialRequest::query()->count())->toBe(0);
});

test('a request can contain multiple product lines with serials', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);
    $firstProduct = Product::factory()->create();
    $secondProduct = Product::factory()->create();

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->assertSee('Agregar una línea')
        ->assertSee('Buscar o seleccionar producto')
        ->set('lines.0.product_id', $firstProduct->id)
        ->set('lines.0.quantity', 2)
        ->set('lines.0.serials', "A-001\nA-002")
        ->call('addLine')
        ->set('lines.1.product_id', $secondProduct->id)
        ->set('lines.1.quantity', 1)
        ->set('lines.1.serials', 'B-001')
        ->call('save')
        ->assertHasNoErrors();

    $serialRequest = MotorcycleSerialRequest::query()->firstOrFail();

    expect($serialRequest->lines)->toHaveCount(2)
        ->and($serialRequest->lines->pluck('product_id')->all())->toBe([$firstProduct->id, $secondProduct->id]);
});

test('an excel workbook creates product lines from chassis serials and preserves the source file', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);
    $product = Product::factory()->create(['name' => 'BR 150 BRF', 'niv' => 'C7']);
    $workbookPath = createMotorcycleSerialWorkbook([
        ['Encabezado sin ubicación fija', null],
        ['SERIAL DE CHASIS', 'Observación'],
        ['8YZC7MCC9TD033582', 'Primero'],
        ['texto adicional ABCC7MCC0TD011111', 'Segundo sin prefijo 8YZ'],
        ['8YZC7MCC9TD033582', 'Duplicado en el archivo'],
        ['8YZXXMCC9TD022222', 'NIV de producto inexistente'],
    ]);
    $upload = UploadedFile::fake()->createWithContent(
        'BR150 BRF AÑO 2026.xlsx',
        file_get_contents($workbookPath),
    );

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->assertDontSee('Procesar Excel')
        ->set('serialWorkbook', $upload)
        ->assertSee('Procesar Excel')
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $product->id)
        ->assertSet('lines.0.quantity', 2)
        ->assertSet('lineFiles.0.name', 'BR150 BRF AÑO 2026.xlsx')
        ->call('save')
        ->assertHasNoErrors();

    $line = MotorcycleSerialRequest::query()->firstOrFail()->lines()->firstOrFail();

    expect($line->serialEntries()->orderBy('id')->pluck('serial')->all())
        ->toBe(['8YZC7MCC9TD033582', 'ABCC7MCC0TD011111'])
        ->and($line->source_file_name)->toBe('Solicitud 1 - BR 150 BRF - C7.xlsx');
    Storage::disk('local')->assertExists($line->source_file_path);
});

test('a workbook containing multiple products creates an independent workbook for every line', function () {
    Storage::fake('local');
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);
    $firstProduct = Product::factory()->create(['name' => 'BR 150 BRF', 'niv' => 'C7']);
    $secondProduct = Product::factory()->create(['name' => 'Modelo Urbano', 'niv' => 'X2']);
    $workbookPath = createMotorcycleSerialWorkbook([
        ['8YZC7MCC9TD033582'],
        ['ABCC7MCC0TD011111'],
        ['8YZX2MCC9TD022222'],
        ['ABCX2MCC0TD044444'],
    ]);
    $upload = UploadedFile::fake()->createWithContent('Varios modelos.xlsx', file_get_contents($workbookPath));

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->set('serialWorkbook', $upload)
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $firstProduct->id)
        ->assertSet('lines.0.quantity', 2)
        ->assertSet('lines.1.product_id', $secondProduct->id)
        ->assertSet('lines.1.quantity', 2)
        ->assertSet('lineFiles.0.name', 'Solicitud pendiente - BR 150 BRF - C7.xlsx')
        ->assertSet('lineFiles.1.name', 'Solicitud pendiente - Modelo Urbano - X2.xlsx')
        ->call('save')
        ->assertHasNoErrors();

    $lines = MotorcycleSerialRequest::query()->firstOrFail()->lines()->orderBy('id')->get();

    expect($lines[0]->source_file_path)->not->toBe($lines[1]->source_file_path)
        ->and($lines[0]->source_file_name)->toBe('Solicitud 1 - BR 150 BRF - C7.xlsx')
        ->and($lines[1]->source_file_name)->toBe('Solicitud 1 - Modelo Urbano - X2.xlsx')
        ->and(app(ExtractSerialsFromWorkbook::class)->handle(Storage::disk('local')->path($lines[0]->source_file_path)))
        ->toBe(['8YZC7MCC9TD033582', 'ABCC7MCC0TD011111'])
        ->and(app(ExtractSerialsFromWorkbook::class)->handle(Storage::disk('local')->path($lines[1]->source_file_path)))
        ->toBe(['8YZX2MCC9TD022222', 'ABCX2MCC0TD044444']);
});

test('the only product line can be removed before saving', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->assertSee('Quitar línea 1')
        ->call('removeLine', 0)
        ->assertSet('lines', [])
        ->assertSet('lineFiles', []);
});

test('serials are displayed in a modal table for each product line', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);
    $line = $serialRequest->lines()->firstOrFail();
    $line->serialEntries()->delete();
    $firstSerial = $line->serialEntries()->create(['serial' => '8YZC7MCC9TD033582']);
    $line->serialEntries()->create(['serial' => 'ABCC7MCC0TD011111']);

    $this->actingAs($administrator);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSee('Ver 2 seriales')
        ->assertDontSeeHtml('<textarea')
        ->call('openSerialModal', 0)
        ->assertSet('serialModalLineIndex', 0)
        ->assertSee('Seriales del producto')
        ->assertSee('8YZC7MCC9TD033582')
        ->assertSee('ABCC7MCC0TD011111')
        ->assertSee((string) $firstSerial->id)
        ->assertSee((string) $line->id)
        ->set('serialSearch', '033582')
        ->assertSee('8YZC7MCC9TD033582')
        ->assertDontSee('ABCC7MCC0TD011111')
        ->assertSee('1 registros')
        ->call('closeSerialModal')
        ->assertSet('serialModalLineIndex', null)
        ->assertSet('serialSearch', '');
});

test('a chassis serial cannot be assigned to more than one request', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $existingRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);
    $existingLine = $existingRequest->lines()->firstOrFail();
    $existingLine->serialEntries()->delete();
    $existingLine->serialEntries()->create(['serial' => '8YZC7MCC9TD033582']);
    $newProduct = Product::factory()->create();

    $this->actingAs($administrator);

    $component = Livewire::test('motorcycle-serial-request-form')
        ->set('lines.0.product_id', $newProduct->id)
        ->set('lines.0.quantity', 1)
        ->set('lines.0.serials', '8yzc7mcc9td033582')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showSerialConflictModal', true)
        ->assertSee('Seriales repetidos')
        ->assertSee('No se puede guardar porque existen seriales repetidos.')
        ->assertSee('Descargar seriales repetidos')
        ->assertDontSee("ya pertenece a la solicitud #{$existingRequest->id}");

    $conflictFilePath = $component->get('serialConflictFilePath');
    Storage::disk('local')->assertExists($conflictFilePath);

    expect(app(ExtractSerialsFromWorkbook::class)->handle(Storage::disk('local')->path($conflictFilePath)))
        ->toBe(['8YZC7MCC9TD033582']);

    $component
        ->call('downloadSerialConflicts')
        ->assertFileDownloaded('Seriales repetidos - Nueva solicitud.xlsx');

    expect(MotorcycleSerialRequest::query()->count())->toBe(1)
        ->and(MotorcycleSerialRequestLineSerial::query()->where('serial', '8YZC7MCC9TD033582')->count())->toBe(1);
});

test('a chassis serial cannot be repeated between lines of the same request', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $firstProduct = Product::factory()->create();
    $secondProduct = Product::factory()->create();

    $this->actingAs($administrator);

    $component = Livewire::test('motorcycle-serial-request-form')
        ->set('lines.0.product_id', $firstProduct->id)
        ->set('lines.0.quantity', 1)
        ->set('lines.0.serials', '8YZC7MCC9TD033582')
        ->call('addLine')
        ->set('lines.1.product_id', $secondProduct->id)
        ->set('lines.1.quantity', 1)
        ->set('lines.1.serials', '8yzc7mcc9td033582')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showSerialConflictModal', true)
        ->assertSee('No se puede guardar porque existen seriales repetidos.');

    $conflictFilePath = $component->get('serialConflictFilePath');

    $component
        ->call('closeSerialConflictModal')
        ->assertSet('showSerialConflictModal', false)
        ->assertSet('serialConflictMessage', '')
        ->assertSet('serialConflictFilePath', null);

    Storage::disk('local')->assertMissing($conflictFilePath);

    expect(MotorcycleSerialRequest::query()->count())->toBe(0);
});

test('done requests cannot be modified', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Done,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSet('persistedDone', true)
        ->assertSee('no admite modificaciones')
        ->assertDontSee('Finalizar')
        ->call('setStatus', MotorcycleSerialRequestStatus::Draft->value)
        ->assertForbidden();

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->call('addLine')
        ->assertForbidden();
});

test('done requests cannot be deleted from the list or form', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
            UserPermission::DeleteMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Done,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-list')
        ->assertDontSee('Eliminar')
        ->call('openDeleteConfirmation', $serialRequest->id)
        ->assertForbidden();

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertDontSee('Eliminar')
        ->call('openDeleteConfirmation')
        ->assertForbidden();

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->call('deleteRequest')
        ->assertForbidden();

    $this->assertModelExists($serialRequest);
});

test('administrators can delete done requests', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Done,
    ]);

    $this->actingAs($administrator);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSee('Eliminar')
        ->call('openDeleteConfirmation')
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteRequest')
        ->assertRedirect(route('motorcycle_serial_requests.index'));

    $this->assertModelMissing($serialRequest);
});

test('users can receive permission to delete done requests', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::DeleteCompletedMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Done,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-list')
        ->assertSee('Eliminar')
        ->call('openDeleteConfirmation', $serialRequest->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteRequest')
        ->assertSee('Solicitud eliminada correctamente.');

    $this->assertModelMissing($serialRequest);
});

test('done status can only be assigned with the finalize action', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->call('setStatus', MotorcycleSerialRequestStatus::Done->value)
        ->assertStatus(422);

    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Draft);
});

test('the save action cannot be manipulated to assign done status', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->set('status', MotorcycleSerialRequestStatus::Done->value)
        ->call('save')
        ->assertStatus(422);

    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Draft);
});

test('read only users cannot modify an existing request', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewMotorcycleSerialRequests->value],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSee('Solo lectura')
        ->call('setStatus', MotorcycleSerialRequestStatus::Done->value)
        ->assertForbidden();
});

test('authorized users can delete requests after confirming', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::DeleteMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-list')
        ->assertSee('Eliminar')
        ->call('openDeleteConfirmation', $serialRequest->id)
        ->assertSet('showDeleteConfirmation', true)
        ->assertSee('¿Eliminar esta solicitud?')
        ->call('deleteRequest')
        ->assertSet('showDeleteConfirmation', false)
        ->assertSee('Solicitud eliminada correctamente.');

    $this->assertModelMissing($serialRequest);
});

test('authorized users can delete an existing request from its form', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
            UserPermission::DeleteMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSee('Guardar')
        ->assertSee('Eliminar')
        ->call('openDeleteConfirmation')
        ->assertSet('showDeleteConfirmation', true)
        ->assertSee('¿Eliminar esta solicitud?')
        ->call('deleteRequest')
        ->assertRedirect(route('motorcycle_serial_requests.index'));

    $this->assertModelMissing($serialRequest);
});

test('delete button is hidden on the form without permission', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
        ],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSee('Guardar')
        ->assertDontSee('Eliminar')
        ->call('openDeleteConfirmation')
        ->assertForbidden();

    $this->assertModelExists($serialRequest);
});

test('users without delete permission cannot delete requests', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewMotorcycleSerialRequests->value],
    ]);
    $serialRequest = MotorcycleSerialRequest::factory()->create();

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-list')
        ->assertDontSee('Eliminar')
        ->call('openDeleteConfirmation', $serialRequest->id)
        ->assertForbidden();

    $this->assertModelExists($serialRequest);
});

test('users without create permission cannot access the new request form', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewMotorcycleSerialRequests->value],
    ]);

    $this->actingAs($user)
        ->get(route('motorcycle_serial_requests.create'))
        ->assertForbidden();
});

test('request action permissions automatically grant consultation access', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('permissions', [UserPermission::CreateMotorcycleSerialRequests->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->permissions)->toContain(
        UserPermission::ViewMotorcycleSerialRequests->value,
        UserPermission::CreateMotorcycleSerialRequests->value,
    );
});

/** @param list<list<mixed>> $rows */
function createMotorcycleSerialWorkbook(array $rows): string
{
    $filePath = tempnam(sys_get_temp_dir(), 'motorcycle-serials-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($filePath);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $filePath;
}

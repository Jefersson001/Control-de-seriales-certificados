<?php

use App\Actions\MotorcycleSerialRequests\ExtractSerialsFromWorkbook;
use App\Models\MotorcycleSerialRequest;
use App\Models\MotorcycleSerialRequestLineSerial;
use App\Models\Product;
use App\Models\User;
use App\MotorcycleSerialRequestStatus;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
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

test('requests can be filtered by status', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
        'request_date' => '2026-08-01',
    ]);
    MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::InProgress,
        'request_date' => '2026-08-02',
    ]);
    MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Done,
        'request_date' => '2026-08-03',
    ]);

    $this->actingAs($administrator);

    Livewire::test('motorcycle-serial-request-list')
        ->assertSee('Todos los estados')
        ->set('statusFilter', MotorcycleSerialRequestStatus::InProgress->value)
        ->assertSee('02/08/2026')
        ->assertDontSee('01/08/2026')
        ->assertDontSee('03/08/2026')
        ->set('statusFilter', MotorcycleSerialRequestStatus::Done->value)
        ->assertSee('03/08/2026')
        ->assertDontSee('02/08/2026');
});

test('the request list filters by category together with search and status', function (string $category) {
    $owner = User::factory()->create(['name' => 'Usuario objetivo']);
    $this->actingAs(User::factory()->create(['permissions' => [UserPermission::ViewMotorcycleSerialRequests->value]]));
    $selected = MotorcycleSerialRequest::factory()->for($owner)->create(['category' => $category, 'status' => MotorcycleSerialRequestStatus::InProgress]);
    $opposite = MotorcycleSerialRequest::factory()->for($owner)->create(['category' => $category === 'nacional' ? 'importados' : 'nacional', 'status' => MotorcycleSerialRequestStatus::InProgress]);
    MotorcycleSerialRequest::factory()->for($owner)->create(['category' => $category, 'status' => MotorcycleSerialRequestStatus::Done]);
    MotorcycleSerialRequest::factory()->create(['category' => $category, 'status' => MotorcycleSerialRequestStatus::InProgress]);

    $component = Livewire::test('motorcycle-serial-request-list')
        ->assertSee('>Categoría</th>', false)
        ->assertSee('request-category-filter', false)
        ->set('search', 'Usuario objetivo')
        ->set('statusFilter', MotorcycleSerialRequestStatus::InProgress->value)
        ->call('setPage', 2)
        ->set('categoryFilter', $category)
        ->assertSet('paginators.page', 1)
        ->assertSee('>'.($category === 'importados' ? 'Importados' : 'Nacional').'</a>', false);

    expect($component->get('requests')->pluck('id')->all())->toBe([$selected->id]);

    $component->set('categoryFilter', 'all');
    expect($component->get('requests')->pluck('id')->sort()->values()->all())->toBe([$selected->id, $opposite->id]);

    $component->set('categoryFilter', 'invalida')->assertSet('categoryFilter', 'all');
})->with(['nacional', 'importados']);

test('saving a new request moves it from draft to in progress', function () {
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
        ->assertSet('category', 'nacional')
        ->assertSee('Categoría')
        ->assertSee('Nacional')
        ->assertSee('Importados')
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
        ->and($serialRequest->status)->toBe(MotorcycleSerialRequestStatus::InProgress);
    expect($serialRequest->category)->toBe('nacional');
});

test('requests can be created with either category', function (string $category) {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $product = Product::factory()->create(['category' => $category]);
    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->set('category', $category)
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.serials', 'SERIAL-CATEGORIA')
        ->call('save')
        ->assertHasNoErrors();

    expect(MotorcycleSerialRequest::query()->sole())
        ->category->toBe($category)
        ->status->toBe(MotorcycleSerialRequestStatus::InProgress);
})->with(['nacional', 'importados']);

test('saving an existing request keeps it in progress in either category', function (MotorcycleSerialRequestStatus $status, string $category) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $serialRequest = MotorcycleSerialRequest::factory()->create(['status' => $status, 'category' => $category]);
    $serialRequest->lines()->first()->product->update(['category' => $category]);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('status', MotorcycleSerialRequestStatus::InProgress->value);

    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::InProgress);
})->with([MotorcycleSerialRequestStatus::Draft, MotorcycleSerialRequestStatus::InProgress])
    ->with(['nacional', 'importados']);

test('unsuccessful saves do not advance the request status', function (bool $duplicateSerials) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $serialRequest = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Draft]);
    $component = Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id]);

    if ($duplicateSerials) {
        $component->set('lines.0.serials', "SERIAL-REPETIDO\nSERIAL-REPETIDO")
            ->call('save')
            ->assertSet('showSerialConflictModal', true);
    } else {
        $component->set('lines.0.quantity', 0)
            ->call('save')
            ->assertHasErrors(['lines.0.quantity' => 'min']);
    }

    $component->assertSet('status', MotorcycleSerialRequestStatus::Draft->value);
    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Draft);
})->with([false, true]);

test('a request category can be loaded and changed', function () {
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
        'category' => 'importados',
    ]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSet('category', 'importados')
        ->set('category', 'nacional')
        ->assertSet('lines', [['product_id' => null, 'quantity' => 1, 'serials' => '']])
        ->assertSet('lineRecordIds', [null])
        ->assertSet('lineSerialMetadata', [[]])
        ->set('lines.0.product_id', $serialRequest->lines()->first()->product_id)
        ->set('lines.0.serials', 'SERIAL-NUEVA-CATEGORIA')
        ->call('save')
        ->assertHasNoErrors();

    expect($serialRequest->refresh()->category)->toBe('nacional');
});

test('requests reject missing or unknown categories', function (string $category, string $rule) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create();

    Livewire::test('motorcycle-serial-request-form')
        ->set('category', $category)
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.serials', 'SERIAL-CATEGORIA')
        ->call('save')
        ->assertHasErrors(['category' => $rule]);

    expect(MotorcycleSerialRequest::query()->count())->toBe(0);
})->with([
    ['', 'required'],
    ['otra', 'in'],
]);

test('request categories cannot be changed without editing access', function (bool $done) {
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => $done ? MotorcycleSerialRequestStatus::Done : MotorcycleSerialRequestStatus::Draft,
        'category' => 'importados',
    ]);
    $user = User::factory()->create([
        'permissions' => $done
            ? [UserPermission::ViewMotorcycleSerialRequests->value, UserPermission::EditMotorcycleSerialRequests->value]
            : [UserPermission::ViewMotorcycleSerialRequests->value],
    ]);
    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->assertSet('category', 'importados')
        ->assertSeeHtml('id="request-category" wire:model.live="category" required disabled')
        ->set('category', 'nacional')
        ->assertForbidden();

    expect($serialRequest->refresh()->category)->toBe('importados');
})->with([false, true]);

test('the request category migration assigns national to existing requests', function () {
    $serialRequest = MotorcycleSerialRequest::factory()->create();
    $migration = require database_path('migrations/2026_11_17_164843_add_category_to_motorcycle_serial_requests_table.php');

    $migration->down();
    $migration->up();

    expect($serialRequest->refresh()->category)->toBe('nacional');
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
    $newProduct = Product::factory()->create(['brand' => 'bera', 'niv' => 'C7']);
    $serialRequest = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Draft]);
    $serialRequest->lines()->first()->update(['product_id' => $originalProduct->id]);

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form', ['requestId' => $serialRequest->id])
        ->set('lines.0.product_id', $newProduct->id)
        ->set('lines.0.quantity', 1)
        ->set('lines.0.serials', '8YZC7MCC3VD000001')
        ->assertSee('Finalizar')
        ->call('openFinalizeConfirmation')
        ->assertSet('showFinalizeConfirmation', true)
        ->assertSee('¿Finalizar esta solicitud?')
        ->assertSee('Sí, finalizar solicitud')
        ->call('finalize')
        ->assertHasNoErrors();

    expect($serialRequest->refresh()->status)->toBe(MotorcycleSerialRequestStatus::Done)
        ->and($serialRequest->lines()->first()->product_id)->toBe($newProduct->id)
        ->and($serialRequest->lines()->first()->quantity)->toBe(1);
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

test('an excel workbook creates product lines from chassis serials without storing the source file', function () {
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
        ->assertDontSee('Archivo Excel')
        ->assertDontSee('Procesar Excel')
        ->set('serialWorkbooks', [$upload])
        ->assertSee('Procesar Excel')
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $product->id)
        ->assertSet('lines.0.quantity', 2)
        ->call('save')
        ->assertHasNoErrors();

    $line = MotorcycleSerialRequest::query()->firstOrFail()->lines()->firstOrFail();

    expect($line->serialEntries()->orderBy('id')->pluck('serial')->all())
        ->toBe(['8YZC7MCC9TD033582', 'ABCC7MCC0TD011111']);
    Storage::disk('local')->assertDirectoryEmpty('motorcycle-serial-request-workbooks');
    expect(Schema::hasColumn('motorcycle_serial_request_lines', 'source_file_name'))->toBeFalse()
        ->and(Schema::hasColumn('motorcycle_serial_request_lines', 'source_file_path'))->toBeFalse();
});

test('a workbook containing multiple products creates the corresponding serial lines without generating workbooks', function () {
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
        ->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $firstProduct->id)
        ->assertSet('lines.0.quantity', 2)
        ->assertSet('lines.1.product_id', $secondProduct->id)
        ->assertSet('lines.1.quantity', 2)
        ->call('save')
        ->assertHasNoErrors();

    $lines = MotorcycleSerialRequest::query()->firstOrFail()->lines()->orderBy('id')->get();

    expect($lines[0]->serialEntries()->orderBy('id')->pluck('serial')->all())
        ->toBe(['8YZC7MCC9TD033582', 'ABCC7MCC0TD011111'])
        ->and($lines[1]->serialEntries()->orderBy('id')->pluck('serial')->all())
        ->toBe(['8YZX2MCC9TD022222', 'ABCX2MCC0TD044444']);
    Storage::disk('local')->assertDirectoryEmpty('motorcycle-serial-request-workbooks');
});

test('multiple workbooks are processed together and grouped into their corresponding product lines', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewMotorcycleSerialRequests->value,
            UserPermission::CreateMotorcycleSerialRequests->value,
        ],
    ]);
    $firstProduct = Product::factory()->create(['name' => 'Producto C7', 'niv' => 'C7']);
    $secondProduct = Product::factory()->create(['name' => 'Producto X2', 'niv' => 'X2']);
    $firstWorkbookPath = createMotorcycleSerialWorkbook([
        ['8YZC7MCC9TD033582'],
        ['8YZC7MCC0TD033583'],
    ]);
    $secondWorkbookPath = createMotorcycleSerialWorkbook([
        ['8YZC7MCC0TD033583'],
        ['ABCC7MCC1TD033584'],
        ['8YZX2MCC9TD022222'],
    ]);
    $firstUpload = UploadedFile::fake()->createWithContent('Primer archivo.xlsx', file_get_contents($firstWorkbookPath));
    $secondUpload = UploadedFile::fake()->createWithContent('Segundo archivo.xlsx', file_get_contents($secondWorkbookPath));

    $this->actingAs($user);

    Livewire::test('motorcycle-serial-request-form')
        ->set('serialWorkbooks', [$firstUpload, $secondUpload])
        ->assertSee('2 archivo(s) seleccionado(s)')
        ->assertSee('Primer archivo.xlsx')
        ->assertSee('Segundo archivo.xlsx')
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $firstProduct->id)
        ->assertSet('lines.0.quantity', 3)
        ->assertSet('lines.1.product_id', $secondProduct->id)
        ->assertSet('lines.1.quantity', 1)
        ->assertSet('serialWorkbooks', [])
        ->assertSet('importMessage', '2 archivo(s) procesado(s): 4 seriales cargados en 2 línea(s) de producto.');
});

test('mixed workbooks only import products in the selected category', function (string $category) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $national = Product::factory()->create(['brand' => 'bera', 'category' => 'nacional', 'niv' => 'C7']);
    $imported = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THK5B']);
    Product::factory()->create(['brand' => 'bera', 'category' => 'importados', 'niv' => 'C7']);
    Product::factory()->create(['brand' => 'zontes', 'category' => 'nacional', 'niv' => 'LD3THK5B']);
    $path = createMotorcycleSerialWorkbook([['8YZC7MCC9TD033582'], ['LD3THK5B6V1700114']]);
    $upload = UploadedFile::fake()->createWithContent('Mezclado.xlsx', file_get_contents($path));
    unlink($path);

    $component = Livewire::test('motorcycle-serial-request-form')
        ->set('category', $category)
        ->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')
        ->assertHasNoErrors();

    /** @var list<array{product_id: int, quantity: int, serials: string}> $lines */
    $lines = $component->get('lines');
    expect($lines)->toHaveCount(2);
    expect(collect($lines)->pluck('product_id')->all())->toContain($category === 'nacional' ? $national->id : $imported->id);

    $component->call('save')->assertHasNoErrors();
    $request = MotorcycleSerialRequest::query()->sole();
    expect($request->category)->toBe($category)
        ->and($request->lines()->whereHas('product', fn ($query) => $query->where('category', '!=', $category))->exists())->toBeFalse();
})->with(['nacional', 'importados']);

test('zontes imports distinguish all three prefixes across multiple workbooks', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $first = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THK5B']);
    $second = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THR6B']);
    $third = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3PWW6A']);
    Product::factory()->create(['brand' => 'bera', 'category' => 'nacional', 'niv' => 'TH']);
    Product::factory()->create(['brand' => 'morini', 'category' => 'importados', 'niv' => 'TH']);
    $firstPath = createMotorcycleSerialWorkbook([['ld3thk5b6v1700114'], ['LD3THR6B5V1601632']]);
    $secondPath = createMotorcycleSerialWorkbook([['LD3THK5B6V1700114'], ['LD3PWW6A3V1600525'], ['LD3THK5C6V1700114']]);
    $uploads = [
        UploadedFile::fake()->createWithContent('Primero.xlsx', file_get_contents($firstPath)),
        UploadedFile::fake()->createWithContent('Segundo.xlsx', file_get_contents($secondPath)),
    ];
    unlink($firstPath);
    unlink($secondPath);

    Livewire::test('motorcycle-serial-request-form')
        ->set('category', 'importados')
        ->set('serialWorkbooks', $uploads)
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $first->id)
        ->assertSet('lines.0.quantity', 1)
        ->assertSet('lines.1.product_id', $second->id)
        ->assertSet('lines.2.product_id', $third->id)
        ->assertSet('importMessage', '2 archivo(s) procesado(s): 3 seriales cargados en 3 línea(s) de producto. Se ignoraron 1 seriales sin coincidencia con un producto de la categoría seleccionada.')
        ->call('save')
        ->assertHasNoErrors();

    expect(MotorcycleSerialRequest::query()->sole()->lines()->count())->toBe(3);
});

test('Morini serials distinguish all seven models alongside Zontes and respect category', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $serials = ['HL4PMSABXVZ000001', 'HL4PKWAB3VZ000001', 'HL4PMVBBXVZ000001', 'HL4PMVBA0VZ000001', 'HL4PU3AB2VZ000001', 'HL4PMVAB8VZ000001', 'HL4PMWAD9VZ000001'];
    $products = collect($serials)->map(fn (string $serial) => Product::factory()->create(['brand' => 'morini', 'category' => 'importados', 'niv' => substr($serial, 0, 8)]));
    $zontes = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THK5B']);
    Product::factory()->create(['brand' => 'morini', 'category' => 'nacional', 'niv' => 'HL4PMVBB']);
    $path = createMotorcycleSerialWorkbook(array_map(fn (string $serial): array => [strtolower($serial)], [...$serials, $serials[0], 'LD3THK5BXV1000001', 'HL4PMSACXVZ000001']));
    $upload = UploadedFile::fake()->createWithContent('Morini y Zontes.xlsx', file_get_contents($path));
    unlink($path);
    $component = Livewire::test('motorcycle-serial-request-form')->set('category', 'importados')->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')->assertHasNoErrors()->assertCount('lines', 8);
    foreach ($products as $index => $product) {
        $component->assertSet('lines.'.$index.'.product_id', $product->id)->assertSet('lines.'.$index.'.quantity', 1);
    }
    $component->assertSet('lines.7.product_id', $zontes->id)->call('save')->assertHasNoErrors();
    expect(MotorcycleSerialRequest::query()->sole()->lines()->count())->toBe(8);
});

test('ambiguous Morini prefixes cannot add serial lines', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->count(2)->create(['brand' => 'morini', 'category' => 'importados', 'niv' => 'HL4PMVBB']);
    $path = createMotorcycleSerialWorkbook([['HL4PMVBBXVZ000001']]);
    $upload = UploadedFile::fake()->createWithContent('Morini.xlsx', file_get_contents($path));
    unlink($path);
    Livewire::test('motorcycle-serial-request-form')->set('category', 'importados')->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')->assertHasErrors('serialWorkbooks')->assertSet('lines', [['product_id' => null, 'quantity' => 1, 'serials' => '']]);
});

test('imports without matches in the selected category leave existing lines untouched', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THK5B']);
    $path = createMotorcycleSerialWorkbook([['LD3THK5B6V1700114']]);
    $upload = UploadedFile::fake()->createWithContent('Importadas.xlsx', file_get_contents($path));
    unlink($path);

    Livewire::test('motorcycle-serial-request-form')
        ->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')
        ->assertHasErrors(['serialWorkbooks'])
        ->assertSee('ninguno corresponde a un producto registrado en la categoría seleccionada')
        ->assertSet('lines', [['product_id' => null, 'quantity' => 1, 'serials' => '']]);
});

test('ambiguous zontes prefixes are rejected before adding any lines', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->count(2)->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THR6B']);
    $path = createMotorcycleSerialWorkbook([['LD3THR6B5V1601632']]);
    $upload = UploadedFile::fake()->createWithContent('368.xlsx', file_get_contents($path));
    unlink($path);

    Livewire::test('motorcycle-serial-request-form')
        ->set('category', 'importados')
        ->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')
        ->assertHasErrors(['serialWorkbooks'])
        ->assertSee('coincide con más de un producto')
        ->assertSet('lines', [['product_id' => null, 'quantity' => 1, 'serials' => '']]);
});

test('product selection and saving respect the request category', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $national = Product::factory()->create(['name' => 'Producto nacional', 'category' => 'nacional']);
    $imported = Product::factory()->create(['name' => 'Producto importado', 'category' => 'importados']);

    Livewire::test('motorcycle-serial-request-form')
        ->assertSee($national->name)
        ->assertDontSee($imported->name)
        ->set('category', 'importados')
        ->assertSee($imported->name)
        ->assertDontSee($national->name)
        ->set('lines.0.product_id', $national->id)
        ->set('lines.0.serials', 'SERIAL-CATEGORIA')
        ->call('save')
        ->assertHasErrors(['lines.0.product_id' => 'exists']);

    expect(MotorcycleSerialRequest::query()->count())->toBe(0);
});

test('changing category clears imported serials pending workbooks and modal state', function (string $category) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $national = Product::factory()->create(['brand' => 'bera', 'category' => 'nacional', 'niv' => 'C7']);
    $imported = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados', 'niv' => 'LD3THK5B']);
    $path = createMotorcycleSerialWorkbook([['8YZC7MCC9TD033582'], ['LD3THK5B6V1700114']]);
    $upload = UploadedFile::fake()->createWithContent('Seriales.xlsx', file_get_contents($path));
    unlink($path);
    $nextCategory = $category === 'nacional' ? 'importados' : 'nacional';

    Livewire::test('motorcycle-serial-request-form')
        ->set('category', $category)
        ->set('requestDate', '2026-11-01')
        ->set('status', MotorcycleSerialRequestStatus::InProgress->value)
        ->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $category === 'nacional' ? $national->id : $imported->id)
        ->call('openSerialModal', 0)
        ->set('serialSearch', 'BUSQUEDA')
        ->set('serialWorkbooks', [$upload])
        ->set('category', $nextCategory)
        ->assertSet('lines', [['product_id' => null, 'quantity' => 1, 'serials' => '']])
        ->assertSet('lineRecordIds', [null])
        ->assertSet('lineSerialMetadata', [[]])
        ->assertSet('serialModalLineIndex', null)
        ->assertSet('serialSearch', '')
        ->assertSet('serialWorkbooks', [])
        ->assertSet('importMessage', null)
        ->assertSet('requestDate', '2026-11-01')
        ->assertSet('status', MotorcycleSerialRequestStatus::InProgress->value)
        ->set('serialWorkbooks', [$upload])
        ->call('importSerialWorkbook')
        ->assertHasNoErrors()
        ->assertSet('lines.0.product_id', $nextCategory === 'nacional' ? $national->id : $imported->id)
        ->assertSet('lines.0.quantity', 1);
})->with(['nacional', 'importados']);

test('changing category clears duplicate serial conflicts and their download', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create();

    $component = Livewire::test('motorcycle-serial-request-form')
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.serials', "SERIAL-REPETIDO\nSERIAL-REPETIDO")
        ->call('save')
        ->assertSet('showSerialConflictModal', true);

    $conflictPath = $component->get('serialConflictFilePath');
    Storage::disk('local')->assertExists($conflictPath);

    $component->set('category', 'importados')
        ->assertSet('showSerialConflictModal', false)
        ->assertSet('serialConflictMessage', '')
        ->assertSet('serialConflictFileName', null)
        ->assertSet('serialConflictFilePath', null)
        ->assertSet('lines', [['product_id' => null, 'quantity' => 1, 'serials' => '']]);

    Storage::disk('local')->assertMissing($conflictPath);
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
        ->assertSet('lines', []);
});

test('serials are displayed in a modal table for each product line', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialRequest = MotorcycleSerialRequest::factory()->create([
        'status' => MotorcycleSerialRequestStatus::Draft,
    ]);
    $line = $serialRequest->lines()->firstOrFail();
    $line->product->update(['name' => 'Producto exportable']);
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
        ->assertSee('Exportar seriales')
        ->assertSee((string) $firstSerial->id)
        ->assertSee((string) $line->id)
        ->set('serialSearch', '033582')
        ->assertSee('8YZC7MCC9TD033582')
        ->assertDontSee('ABCC7MCC0TD011111')
        ->assertSee('1 registros')
        ->call('exportModalSerials')
        ->assertFileDownloaded("Seriales - Solicitud {$serialRequest->id} - Producto exportable.xlsx")
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

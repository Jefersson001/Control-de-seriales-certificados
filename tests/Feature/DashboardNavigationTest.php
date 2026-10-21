<?php

use App\Actions\GetDashboardStatistics;
use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MotorcycleSerialRequest;
use App\Models\MsCertificado;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use App\Models\VehicleIdentificationRecordManagement;
use App\MotorcycleSerialRequestStatus;
use App\ReturnStatus;
use App\UserPermission;
use App\UserRole;
use App\VehicleIdentificationRecordManagementStatus;
use Livewire\Livewire;

test('dashboard has no introductory banner and automatically refreshes current totals every minute', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $component = Livewire::actingAs($admin)->test('dashboard-overview')
        ->assertDontSee('Resumen general')
        ->assertDontSee('Tu operación, de un vistazo.')
        ->assertDontSee('Bienvenido,')
        ->assertDontSee('Actualizar cifras')
        ->assertDontSee('Actualizado:')
        ->assertSeeHtml('wire:poll.60s.keep-alive')
        ->assertSet('statistics.totals.products', 0);

    Product::factory()->create();

    $component->call('$refresh')->assertSet('statistics.totals.products', 1);
});

test('the dashboard menu entry and page heading are named resumen general', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))->assertSuccessful()
        ->assertSeeHtml('<span>Resumen general</span>')
        ->assertSeeHtml('<h1 class="text-lg font-semibold">Resumen general</h1>')
        ->assertDontSeeHtml('<span>Inicio</span>');
});

test('returned serials and return documents are displayed separately with their correct destinations', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    MsCertificado::factory()->count(3)->create(['status' => CertificateStatus::Returned]);
    $statistics = app(GetDashboardStatistics::class)->handle($admin);

    expect($statistics['totals']['returns'])->toBe(0)
        ->and($statistics['master']['returned'])->toBe(3);

    $this->actingAs($admin)->get(route('dashboard'))->assertSuccessful()
        ->assertSee('Documentos de devolución')
        ->assertSee('Seriales devueltos en el maestro')
        ->assertSeeHtml('href="'.route('returns.index').'"')
        ->assertSeeHtml('href="'.route('certificates.index', ['status' => 'returned']).'"');
});

test('dashboard master shows all totals without selectors and keeps category and status links', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $component = Livewire::actingAs($admin)->test('dashboard-overview');
    foreach (['duplicates', 'unique_niv', 'invalid_niv_length', 'invalid_records', 'group_by_certificate'] as $filter) {
        $component->assertSeeHtml('href="'.route('certificates.index', ['records' => $filter]).'"');
    }
    foreach (CertificateStatus::cases() as $status) {
        $component->assertSeeHtml('href="'.route('certificates.index', ['status' => $status->value]).'"');
    }

    $component->assertDontSee('Filtrar registros')
        ->assertDontSee('Filtrar por status')
        ->assertDontSee('Ver registros filtrados')
        ->assertDontSeeHtml('<select')
        ->assertSeeHtml('href="'.route('certificates.index').'"');
});

test('master filters are initialized from a dashboard link and limit the records shown', function () {
    $user = User::factory()->create(['permissions' => [UserPermission::ViewCertificates->value]]);
    $returned = MsCertificado::factory()->create(['niv' => '12345678901234567', 'status' => CertificateStatus::Returned]);
    MsCertificado::factory()->create(['niv' => $returned->niv, 'status' => CertificateStatus::Dispatched]);
    MsCertificado::factory()->create(['niv' => '12345678901234568', 'status' => CertificateStatus::Returned]);

    Livewire::actingAs($user)->withQueryParams(['records' => 'duplicates', 'status' => 'returned'])
        ->test('certificate-master')
        ->assertSet('recordFilter', 'duplicates')->assertSet('statusFilter', 'returned')
        ->assertSet('certificates', fn ($records): bool => $records->total() === 1 && $records->first()->id === $returned->id);
});

test('workflows always show all states without selectors and keep filtered destination links', function (string $componentName, string $routeName, string $property) {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $request = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Draft]);
    $doneRequest = MotorcycleSerialRequest::factory()->create(['status' => MotorcycleSerialRequestStatus::Done]);
    VehicleIdentificationRecordManagement::factory()->create(['motorcycle_serial_request_id' => $request->id, 'status' => VehicleIdentificationRecordManagementStatus::Draft]);
    VehicleIdentificationRecordManagement::factory()->create(['motorcycle_serial_request_id' => $doneRequest->id, 'status' => VehicleIdentificationRecordManagementStatus::Done]);
    Dispatch::query()->create(['name' => 'DRAFT-DISPATCH', 'status' => DispatchStatus::Draft]);
    Dispatch::query()->create(['name' => 'DONE-DISPATCH', 'status' => DispatchStatus::Done]);
    ProductReturn::factory()->create(['created_by' => $admin->id, 'status' => ReturnStatus::Draft]);
    ProductReturn::factory()->create(['created_by' => $admin->id, 'status' => ReturnStatus::Done]);

    Livewire::actingAs($admin)->test('dashboard-overview')
        ->assertSeeText('Todos los estados')
        ->assertSeeText('2 en total')
        ->assertDontSee('Filtrar por estado')
        ->assertDontSeeHtml('wire:model.live="stateFilters.')
        ->assertDontSeeHtml('wire:key="bar-')
        ->assertSeeHtml('href="'.route($routeName).'"')
        ->assertSeeHtml('href="'.route($routeName, ['status' => 'done']).'"');

    Livewire::withQueryParams(['status' => 'done'])->test($componentName)
        ->assertSet('statusFilter', 'done')
        ->assertSet($property, fn ($records): bool => $records->total() === 1 && $records->first()->status->value === 'done')
        ->set('statusFilter', 'draft')
        ->assertSet($property, fn ($records): bool => $records->total() === 1 && $records->first()->status->value === 'draft');
})->with([
    'requests' => ['motorcycle-serial-request-list', 'motorcycle_serial_requests.index', 'requests'],
    'managements' => ['vehicle-identification-record-management-list', 'vehicle_identification_record_management.index', 'records'],
    'dispatches' => ['dispatch-list', 'dispatches.index', 'dispatches'],
    'returns' => ['return-list', 'returns.index', 'returns'],
]);

test('invalid query string status filters fall back to all records', function (string $componentName) {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Livewire::actingAs($admin)->withQueryParams(['status' => 'unexpected'])
        ->test($componentName)->assertSet('statusFilter', 'all');
})->with(['certificate-master', 'motorcycle-serial-request-list', 'vehicle-identification-record-management-list', 'dispatch-list', 'return-list']);

test('users cannot access filtered master records without module permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('certificates.index', ['status' => 'returned']))->assertForbidden();
});

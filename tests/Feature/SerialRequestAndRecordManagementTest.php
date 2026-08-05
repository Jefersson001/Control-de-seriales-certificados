<?php

use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('administrators can access the new modules', function (string $routeName, string $heading) {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route($routeName))
        ->assertSuccessful()
        ->assertSee($heading);
})->with([
    'motorcycle serial requests' => [
        'motorcycle_serial_requests.index',
        'Solicitud de seriales de motos',
    ],
    'vehicle identification record management' => [
        'vehicle_identification_record_management.index',
        'Gestión de Constancia de Registro de Número de Identificación de Vehículo',
    ],
]);

test('authorized users can see and access their assigned module', function (
    UserPermission $permission,
    string $routeName,
    string $heading,
) {
    $user = User::factory()->create([
        'permissions' => [$permission->value],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Solicitudes')
        ->assertSee('data-requests-menu', false)
        ->assertSee('group order-[998]', false)
        ->assertSee(route($routeName))
        ->assertSee($heading);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertSuccessful()
        ->assertSee($heading);
})->with([
    'motorcycle serial requests' => [
        UserPermission::ViewMotorcycleSerialRequests,
        'motorcycle_serial_requests.index',
        'Solicitud de seriales de motos',
    ],
    'vehicle identification record management' => [
        UserPermission::ViewVehicleIdentificationRecordManagement,
        'vehicle_identification_record_management.index',
        'Gestión de Constancia de Registro de Número de Identificación de Vehículo',
    ],
]);

test('users without permission cannot see or access the new modules', function (string $routeName) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route($routeName));

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'motorcycle serial requests' => ['motorcycle_serial_requests.index'],
    'vehicle identification record management' => ['vehicle_identification_record_management.index'],
]);

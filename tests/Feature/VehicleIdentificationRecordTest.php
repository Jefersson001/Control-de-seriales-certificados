<?php

use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('administrators can access the vehicle identification record module', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route('vehicle_identification_records.index'))
        ->assertSuccessful()
        ->assertSee('Importar registros desde PDF')
        ->assertSee('Seleccionar PDF');
});

test('authorized users can see and access the vehicle identification record menu', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewVehicleIdentificationRecord->value],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(route('vehicle_identification_records.index'))
        ->assertSee('Constancia de Registro de Número de Identificación de Vehículo');

    $this->actingAs($user)
        ->get(route('vehicle_identification_records.index'))
        ->assertSuccessful()
        ->assertSee('Seleccionar PDF');
});

test('users without permission cannot see or access the vehicle identification record module', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route('vehicle_identification_records.index'));

    $this->actingAs($user)
        ->get(route('vehicle_identification_records.index'))
        ->assertForbidden();
});

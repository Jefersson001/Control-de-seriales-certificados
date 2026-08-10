<?php

use App\Models\SystemSetting;
use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the original livewire payload limit defaults to one megabyte', function () {
    expect(SystemSetting::livewirePayloadMaxMb())->toBe(1)
        ->and(config('livewire.payload.max_size'))->toBe(1024 * 1024);
});

test('administrators can configure the livewire payload limit in megabytes', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator);

    Livewire::test('system-settings')
        ->assertSet('livewirePayloadMaxMb', 1)
        ->assertSee('Tamaño máximo de petición Livewire')
        ->set('livewirePayloadMaxMb', 25)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Parámetros del sistema actualizados correctamente.');

    expect(SystemSetting::livewirePayloadMaxMb())->toBe(25)
        ->and(config('livewire.payload.max_size'))->toBe(25 * 1024 * 1024);
});

test('users with consultation permission can view but cannot edit system settings', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewSystemSettings->value],
    ]);

    $this->actingAs($user)
        ->get(route('system_settings.index'))
        ->assertSuccessful()
        ->assertSee('Parámetros del sistema')
        ->assertDontSee('Guardar configuración');

    Livewire::actingAs($user)
        ->test('system-settings')
        ->call('save')
        ->assertForbidden();
});

test('users without permission cannot access system settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('system_settings.index'))
        ->assertForbidden();
});

test('system setting values must remain within the server limit', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator);

    Livewire::test('system-settings')
        ->set('livewirePayloadMaxMb', 40)
        ->call('save')
        ->assertHasErrors(['livewirePayloadMaxMb' => 'max']);
});

<?php

use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('administrators can access dispatch and return modules', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dispatches.index'))
        ->assertSuccessful()
        ->assertSee('Gestión de despachos');

    $this->actingAs($administrator)
        ->get(route('returns.index'))
        ->assertSuccessful()
        ->assertSee('Gestión de devoluciones');
});

test('dispatch menu appears after requests and before configuration', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('data-requests-menu', false)
        ->assertSee('data-dispatch-menu', false)
        ->assertSee('data-configuration-menu', false)
        ->assertSee('group order-[998]', false)
        ->assertSee(route('dispatches.index'))
        ->assertSee(route('returns.index'));
});

test('users only see and access authorized dispatch modules', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewDispatches->value],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Despacho')
        ->assertSee(route('dispatches.index'))
        ->assertDontSee(route('returns.index'));

    $this->actingAs($user)
        ->get(route('dispatches.index'))
        ->assertSuccessful();

    $this->actingAs($user)
        ->get(route('returns.index'))
        ->assertForbidden();
});

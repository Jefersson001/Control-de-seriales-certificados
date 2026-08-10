<?php

use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('administrators can access the certificate correction module', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route('certificate_corrections.index'))
        ->assertSuccessful()
        ->assertSee('Corrección Maestro Seriales Certificados');
});

test('authorized users can access the hidden certificate correction module directly', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificateCorrections->value],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route('certificate_corrections.index'));

    $this->actingAs($user)
        ->get(route('certificate_corrections.index'))
        ->assertSuccessful()
        ->assertSee('Este espacio está preparado');
});

test('users without permission cannot see or access the certificate correction module', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route('certificate_corrections.index'));

    $this->actingAs($user)
        ->get(route('certificate_corrections.index'))
        ->assertForbidden();
});

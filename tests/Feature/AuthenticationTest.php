<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('valid credentials authenticate the user and redirect to the dashboard', function () {
    $user = User::factory()->create([
        'email' => 'admin@ejemplo.com',
        'password' => 'AdminTemporal2026!',
        'remember_token' => null,
    ]);

    $response = $this->from(route('login'))->post(route('login.authenticate'), [
        'email' => $user->email,
        'password' => 'AdminTemporal2026!',
    ]);

    $response
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRememberToken())->toBeEmpty();
});

test('users can authenticate and keep their session active', function (string $rememberValue) {
    $user = User::factory()->create([
        'email' => 'usuario@ejemplo.com',
        'password' => 'ClaveTemporal2026!',
        'remember_token' => null,
    ]);

    $response = $this->from(route('login'))->post(route('login.authenticate'), [
        'email' => $user->email,
        'password' => 'ClaveTemporal2026!',
        'remember' => $rememberValue,
    ]);

    $response
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRememberToken())->not->toBeNull();
})->with([
    'value sent by the corrected checkbox' => '1',
    'legacy browser checkbox value' => 'on',
]);

test('an explicitly disabled remember option does not create a remember token', function () {
    $user = User::factory()->create([
        'email' => 'usuario@ejemplo.com',
        'password' => 'ClaveTemporal2026!',
        'remember_token' => null,
    ]);

    $response = $this->from(route('login'))->post(route('login.authenticate'), [
        'email' => $user->email,
        'password' => 'ClaveTemporal2026!',
        'remember' => '0',
    ]);

    $response
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRememberToken())->toBeEmpty();
});

test('users cannot authenticate when their password has expired', function () {
    $user = User::factory()->create([
        'email' => 'vencido@ejemplo.com',
        'password' => 'ClaveTemporal2026!',
        'password_never_expires' => false,
        'password_expiration_days' => 30,
        'password_changed_at' => now()->subDays(31),
    ]);

    $response = $this->from(route('login'))->post(route('login.authenticate'), [
        'email' => $user->email,
        'password' => 'ClaveTemporal2026!',
    ]);

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => 'Tu contraseña ha vencido. Solicita a un administrador que actualice tu contraseña.',
        ]);

    $this->assertGuest();
});

test('users can authenticate while their password is still valid', function () {
    $user = User::factory()->create([
        'email' => 'vigente@ejemplo.com',
        'password' => 'ClaveTemporal2026!',
        'password_never_expires' => false,
        'password_expiration_days' => 30,
        'password_changed_at' => now()->subDays(29),
    ]);

    $this->post(route('login.authenticate'), [
        'email' => $user->email,
        'password' => 'ClaveTemporal2026!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('an active session is closed when the password has expired', function () {
    $user = User::factory()->create([
        'password_never_expires' => false,
        'password_expiration_days' => 30,
        'password_changed_at' => now()->subDays(31),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => 'Tu contraseña ha vencido. Solicita a un administrador que actualice tu contraseña.',
        ]);

    $this->assertGuest();
});

test('invalid credentials report that they are incorrect', function () {
    User::factory()->create([
        'email' => 'admin@ejemplo.com',
        'password' => 'AdminTemporal2026!',
    ]);

    $response = $this->from(route('login'))->post(route('login.authenticate'), [
        'email' => 'admin@ejemplo.com',
        'password' => 'contraseña-incorrecta',
    ]);

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => 'El correo o la contraseña son incorrectos.',
        ]);

    $this->assertGuest();
});

test('guests cannot access the dashboard', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Bienvenido, '.$user->name);
});

test('the administrative sidebar keeps its menu scrollable and logout visible', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('data-sidebar-menu', false)
        ->assertSee('overflow-y-auto', false)
        ->assertSee('overflow-x-hidden', false)
        ->assertSee('data-sidebar-footer', false)
        ->assertSee('Cerrar sesión');
});

test('authenticated users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

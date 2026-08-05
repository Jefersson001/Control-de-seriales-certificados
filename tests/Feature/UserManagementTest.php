<?php

use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('administrators can see the users menu', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Usuarios');
});

test('regular users cannot see the users menu', function () {
    $user = User::factory()->create([
        'role' => UserRole::User,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route('users.index'));
});

test('administrators can access user administration', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee('Buscar por nombre o correo');
});

test('regular users cannot access user administration', function () {
    $user = User::factory()->create([
        'role' => UserRole::User,
    ]);

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertForbidden();
});

test('users with consultation permission have read only access to user administration', function () {
    $user = User::factory()->create([
        'role' => UserRole::User,
        'permissions' => [UserPermission::ViewUsers->value],
    ]);
    User::factory()->create(['name' => 'Usuario visible']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(route('users.index'));

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertSuccessful();

    Livewire::test('user-manager')
        ->assertSee('Usuario visible')
        ->assertSee('Solo lectura')
        ->assertDontSee('Crear usuario')
        ->call('openCreateForm')
        ->assertForbidden();
});

test('user list links to separate create and record forms', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create();

    $this->actingAs($administrator)
        ->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee(route('users.create'))
        ->assertSee(route('users.edit', $user));

    Livewire::test('user-manager')->assertDontSee('Editar');

    $this->get(route('users.create'))
        ->assertSuccessful()
        ->assertSee('Crear usuario');

    $this->get(route('users.edit', $user))
        ->assertSuccessful()
        ->assertSee($user->email)
        ->assertSee('Guardar')
        ->assertSee('Eliminar');
});

test('user form is read only without edit permission', function () {
    $viewer = User::factory()->create([
        'permissions' => [UserPermission::ViewUsers->value],
    ]);
    $user = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('users.edit', $user))
        ->assertSuccessful()
        ->assertSee('Volver')
        ->assertDontSee('Guardar');

    $this->get(route('users.create'))->assertForbidden();
});

test('users can be deleted from their form', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('user-manager', ['formOnly' => true, 'userId' => $user->id])
        ->assertSee('Guardar')
        ->assertSee('Eliminar')
        ->call('openDeleteConfirmation', $user->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteUser')
        ->assertRedirect(route('users.index'));

    $this->assertModelMissing($user);
});

test('administrators can search users in real time', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    User::factory()->create([
        'name' => 'María Encontrada',
        'email' => 'encontrada@ejemplo.com',
    ]);
    User::factory()->create([
        'name' => 'Pedro Oculto',
        'email' => 'oculto@ejemplo.com',
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->set('search', 'Encontrada')
        ->assertSee('María Encontrada')
        ->assertDontSee('Pedro Oculto');
});

test('administrators can create users from the component', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('openCreateForm')
        ->set('name', 'Usuario de prueba')
        ->set('email', 'usuario@ejemplo.com')
        ->set('role', UserRole::User->value)
        ->set('password', 'contraseña-segura')
        ->set('password_confirmation', 'contraseña-segura')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Usuario creado correctamente.');

    $createdUser = User::query()
        ->where('email', 'usuario@ejemplo.com')
        ->firstOrFail();

    expect($createdUser->role)->toBe(UserRole::User)
        ->and(Hash::check('contraseña-segura', $createdUser->password))->toBeTrue()
        ->and($createdUser->password_never_expires)->toBeTrue()
        ->and($createdUser->password_changed_at)->not->toBeNull();
});

test('password expiration appears before menu permissions in the user form', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator);

    $this->get(route('users.create'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Vencimiento de contraseña',
            'Acceso a menús y funciones',
        ]);
});

test('administrators can configure password expiration when creating users', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('openCreateForm')
        ->set('name', 'Usuario con vencimiento')
        ->set('email', 'vence@ejemplo.com')
        ->set('password', 'contraseña-segura')
        ->set('password_confirmation', 'contraseña-segura')
        ->set('passwordHasExpiration', true)
        ->set('passwordExpirationDays', 45)
        ->call('save')
        ->assertHasNoErrors();

    $createdUser = User::query()->where('email', 'vence@ejemplo.com')->firstOrFail();

    expect($createdUser->password_never_expires)->toBeFalse()
        ->and($createdUser->password_expiration_days)->toBe(45)
        ->and($createdUser->password_changed_at)->not->toBeNull();
});

test('users with create permission can create regular accounts without elevating privileges', function () {
    $userManager = User::factory()->create([
        'role' => UserRole::User,
        'permissions' => [
            UserPermission::ViewUsers->value,
            UserPermission::CreateUsers->value,
        ],
    ]);

    $this->actingAs($userManager);

    Livewire::test('user-manager')
        ->call('openCreateForm')
        ->set('name', 'Cuenta delegada')
        ->set('email', 'delegada@ejemplo.com')
        ->set('role', UserRole::Admin->value)
        ->set('permissions', [UserPermission::DeleteUsers->value])
        ->set('password', 'contraseña-segura')
        ->set('password_confirmation', 'contraseña-segura')
        ->call('save')
        ->assertHasNoErrors();

    $createdUser = User::query()->where('email', 'delegada@ejemplo.com')->firstOrFail();

    expect($createdUser->role)->toBe(UserRole::User)
        ->and($createdUser->permissions)->toBe([]);
});

test('administrators can edit users from the component', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $user = User::factory()->create([
        'name' => 'Nombre anterior',
        'email' => 'anterior@ejemplo.com',
        'role' => UserRole::User,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->assertSet('editingUserId', $user->id)
        ->set('name', 'Nombre actualizado')
        ->set('email', 'actualizado@ejemplo.com')
        ->set('role', UserRole::Admin->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Usuario actualizado correctamente.');

    expect($user->refresh())
        ->name->toBe('Nombre actualizado')
        ->email->toBe('actualizado@ejemplo.com')
        ->role->toBe(UserRole::Admin);
});

test('password update date changes only when an administrator sets a new password', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $originalPasswordChangedAt = now()->subMonth()->startOfSecond();
    $user = User::factory()->create([
        'name' => 'Usuario contraseña',
        'password_changed_at' => $originalPasswordChangedAt,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('name', 'Usuario sin cambio de contraseña')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->password_changed_at->equalTo($originalPasswordChangedAt))->toBeTrue();

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('password', 'contraseña-nueva')
        ->set('password_confirmation', 'contraseña-nueva')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->password_changed_at->greaterThan($originalPasswordChangedAt))->toBeTrue()
        ->and(Hash::check('contraseña-nueva', $user->password))->toBeTrue();
});

test('expiration days are required when password expiration is enabled', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('openCreateForm')
        ->set('name', 'Usuario sin días')
        ->set('email', 'sin-dias@ejemplo.com')
        ->set('password', 'contraseña-segura')
        ->set('password_confirmation', 'contraseña-segura')
        ->set('passwordHasExpiration', true)
        ->call('save')
        ->assertHasErrors('passwordExpirationDays');
});

test('users with edit permission can edit regular accounts but not administrators', function () {
    $userManager = User::factory()->create([
        'role' => UserRole::User,
        'permissions' => [
            UserPermission::ViewUsers->value,
            UserPermission::EditUsers->value,
        ],
    ]);
    $regularUser = User::factory()->create(['name' => 'Nombre anterior']);
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($userManager);

    Livewire::test('user-manager')
        ->call('editUser', $regularUser->id)
        ->set('name', 'Nombre delegado')
        ->call('save')
        ->assertHasNoErrors();

    expect($regularUser->refresh()->name)->toBe('Nombre delegado');

    Livewire::test('user-manager')
        ->call('editUser', $administrator->id)
        ->assertForbidden();
});

test('administrators can assign menu permissions to users', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $user = User::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('permissions', [
            UserPermission::ImportCertificates->value,
            UserPermission::DeleteCertificates->value,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->permissions)->toContain(
        UserPermission::ViewCertificates->value,
        UserPermission::ImportCertificates->value,
        UserPermission::DeleteCertificates->value,
    );
});

test('user management actions automatically grant consultation permission', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $user = User::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('permissions', [UserPermission::DeleteUsers->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->permissions)->toContain(
        UserPermission::ViewUsers->value,
        UserPermission::DeleteUsers->value,
    );
});

test('administrators cannot remove their own administrator role', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $administrator->id)
        ->set('role', UserRole::User->value)
        ->call('save')
        ->assertHasErrors('role');

    expect($administrator->refresh()->role)->toBe(UserRole::Admin);
});

test('administrators can delete another user after confirming', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $user = User::factory()->create([
        'name' => 'Usuario para eliminar',
        'email' => 'eliminar@ejemplo.com',
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('openDeleteConfirmation', $user->id)
        ->assertSet('showDeleteConfirmation', true)
        ->assertSet('deletingUserId', $user->id)
        ->assertSee('¿Eliminar este usuario?')
        ->assertSee('Usuario para eliminar')
        ->call('deleteUser')
        ->assertSet('showDeleteConfirmation', false)
        ->assertSee('Usuario eliminado correctamente.');

    $this->assertModelMissing($user);
});

test('users with delete permission can delete regular accounts but not administrators', function () {
    $userManager = User::factory()->create([
        'role' => UserRole::User,
        'permissions' => [
            UserPermission::ViewUsers->value,
            UserPermission::DeleteUsers->value,
        ],
    ]);
    $regularUser = User::factory()->create();
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($userManager);

    Livewire::test('user-manager')
        ->call('openDeleteConfirmation', $regularUser->id)
        ->call('deleteUser')
        ->assertSee('Usuario eliminado correctamente.');

    $this->assertModelMissing($regularUser);

    Livewire::test('user-manager')
        ->call('openDeleteConfirmation', $administrator->id)
        ->assertForbidden();

    $this->assertModelExists($administrator);
});

test('administrators cannot delete their own account', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->assertDontSeeHtml("openDeleteConfirmation({$administrator->id})")
        ->call('openDeleteConfirmation', $administrator->id)
        ->assertForbidden();

    $this->assertModelExists($administrator);
});

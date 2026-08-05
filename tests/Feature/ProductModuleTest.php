<?php

use App\Models\Product;
use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('administrators can access the products module', function () {
    $administrator = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($administrator)
        ->get(route('products.index'))
        ->assertSuccessful()
        ->assertSee('Buscar por descripción')
        ->assertSee('Importar Excel')
        ->assertSee('Exportar data')
        ->assertSee(route('products.export'))
        ->assertSee('Crear producto');
});

test('authorized users have read only access to products', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewProducts->value],
    ]);
    Product::factory()->create(['name' => 'Motocicleta urbana']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Configuración')
        ->assertSee('data-configuration-menu', false)
        ->assertSee('group order-[999]', false)
        ->assertSee(route('products.index'))
        ->assertDontSee(route('users.index'));

    $this->actingAs($user);

    Livewire::test('product-manager')
        ->assertSee('Motocicleta urbana')
        ->assertSee('Solo lectura')
        ->assertDontSee('Crear producto')
        ->call('openCreateForm')
        ->assertForbidden();
});

test('users without permission cannot see or access the products module', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route('products.index'));

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertForbidden();
});

test('products can be searched by name in real time', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    Product::factory()->create(['name' => 'Motocicleta deportiva']);
    Product::factory()->create(['name' => 'Casco protector']);

    $this->actingAs($administrator);

    Livewire::test('product-manager')
        ->set('search', 'deportiva')
        ->assertSee('Motocicleta deportiva')
        ->assertDontSee('Casco protector');
});

test('authorized users can create products', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewProducts->value,
            UserPermission::CreateProducts->value,
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('product-manager')
        ->call('openCreateForm')
        ->set('description', 'Motocicleta de prueba')
        ->set('firstValue', 'Clase A')
        ->set('secondValue', 'Tipo B')
        ->set('niv', 'NIV-PRODUCTO-001')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Producto creado correctamente.');

    $product = Product::query()->where('name', 'Motocicleta de prueba')->firstOrFail();

    $this->assertModelExists($product);
});

test('product names must be unique', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    Product::factory()->create(['name' => 'Producto existente']);

    $this->actingAs($administrator);

    Livewire::test('product-manager')
        ->call('openCreateForm')
        ->set('description', 'Producto existente')
        ->call('save')
        ->assertHasErrors('description')
        ->assertSee('Ya existe un producto con esta descripción.');
});

test('authorized users can edit products', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewProducts->value,
            UserPermission::EditProducts->value,
        ],
    ]);
    $product = Product::factory()->create(['name' => 'Nombre anterior']);

    $this->actingAs($user);

    Livewire::test('product-manager')
        ->call('editProduct', $product->id)
        ->assertSet('editingProductId', $product->id)
        ->set('description', 'Nombre actualizado')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Producto actualizado correctamente.');

    expect($product->refresh()->name)->toBe('Nombre actualizado');
});

test('authorized users can delete products after confirming', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewProducts->value,
            UserPermission::DeleteProducts->value,
        ],
    ]);
    $product = Product::factory()->create(['name' => 'Producto para eliminar']);

    $this->actingAs($user);

    Livewire::test('product-manager')
        ->call('openDeleteConfirmation', $product->id)
        ->assertSet('showDeleteConfirmation', true)
        ->assertSee('¿Eliminar este producto?')
        ->assertSee('Producto para eliminar')
        ->call('deleteProduct')
        ->assertSet('showDeleteConfirmation', false)
        ->assertSee('Producto eliminado correctamente.');

    $this->assertModelMissing($product);
});

test('products related to requests show a friendly message instead of being deleted', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewProducts->value,
            UserPermission::DeleteProducts->value,
        ],
    ]);
    $product = Product::factory()->create(['name' => 'Producto relacionado']);
    $serialRequest = \App\Models\MotorcycleSerialRequest::factory()->create();
    $serialRequest->lines()->first()->update(['product_id' => $product->id]);

    $this->actingAs($user);

    Livewire::test('product-manager')
        ->call('openDeleteConfirmation', $product->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteProduct')
        ->assertSet('showDeleteConfirmation', false)
        ->assertSet('errorMessage', 'No se puede eliminar este producto porque está relacionado con una o más solicitudes de seriales de motos.')
        ->assertSee('No se puede eliminar este producto porque está relacionado')
        ->assertHasNoErrors();

    $this->assertModelExists($product);
    $this->assertModelExists($serialRequest);
});

test('product management permissions automatically grant consultation access', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('user-manager')
        ->call('editUser', $user->id)
        ->set('permissions', [UserPermission::CreateProducts->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->permissions)->toContain(
        UserPermission::ViewProducts->value,
        UserPermission::CreateProducts->value,
    );
});

test('product list links to separate create and record forms', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $product = Product::factory()->create();

    $this->actingAs($administrator)
        ->get(route('products.index'))
        ->assertSuccessful()
        ->assertSee(route('products.create'))
        ->assertSee(route('products.edit', $product));

    Livewire::test('product-manager')
        ->assertDontSee('Editar')
        ->assertDontSee('>Año<', false);

    $this->get(route('products.create'))
        ->assertSuccessful()
        ->assertSee('Nuevo producto');

    $this->get(route('products.edit', $product))
        ->assertSuccessful()
        ->assertSee($product->name)
        ->assertDontSee('product-year', false)
        ->assertSee('Guardar')
        ->assertSee('Eliminar');
});

test('product form is read only without edit permission', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewProducts->value],
    ]);
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->get(route('products.edit', $product))
        ->assertSuccessful()
        ->assertSee('Volver')
        ->assertDontSee('Guardar');

    $this->get(route('products.create'))->assertForbidden();
});

test('products can be deleted from their form', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $product = Product::factory()->create();

    $this->actingAs($administrator);

    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSee('Guardar')
        ->assertSee('Eliminar')
        ->call('openDeleteConfirmation', $product->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteProduct')
        ->assertRedirect(route('products.index'));

    $this->assertModelMissing($product);
});

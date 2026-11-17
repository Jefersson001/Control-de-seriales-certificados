<?php

use App\Models\MotorcycleSerialRequest;
use App\Models\NivComplianceCertificate;
use App\Models\Product;
use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

test('the product list displays categories', function () {
    $this->actingAs(User::factory()->create([
        'permissions' => [UserPermission::ViewProducts->value],
    ]));
    Product::factory()->create(['category' => 'nacional']);
    Product::factory()->create(['category' => 'importados']);

    Livewire::test('product-manager')
        ->assertSee('product-category-filter', false)
        ->assertSee('>Categoría</th>', false)
        ->assertSee('>Nacional</td>', false)
        ->assertSee('>Importados</td>', false);
});

test('products can be filtered by category together with search', function (string $category) {
    $this->actingAs(User::factory()->create([
        'permissions' => [UserPermission::ViewProducts->value],
    ]));
    $national = Product::factory()->create(['name' => 'Producto nacional', 'category' => 'nacional', 'niv' => 'NIV-COMUN']);
    $imported = Product::factory()->create(['name' => 'Producto importado', 'category' => 'importados', 'niv' => 'NIV-COMUN']);
    $other = Product::factory()->create(['name' => 'Otro producto', 'category' => $category]);
    $visible = $category === 'nacional' ? $national : $imported;
    $hidden = $category === 'nacional' ? $imported : $national;

    Livewire::test('product-manager')
        ->set('categoryFilter', $category)
        ->assertSee($visible->name)
        ->assertDontSee($hidden->name)
        ->set('search', 'NIV-COMUN')
        ->assertSee($visible->name)
        ->assertDontSee($hidden->name)
        ->assertDontSee($other->name)
        ->assertSee(route('products.export', ['search' => 'NIV-COMUN', 'category' => $category]))
        ->set('categoryFilter', '')
        ->assertSee($national->name)
        ->assertSee($imported->name)
        ->assertDontSee($other->name);
})->with(['nacional', 'importados']);

test('changing the category filter resets product pagination', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->count(11)->create(['category' => 'nacional']);

    Livewire::test('product-manager')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('categoryFilter', 'importados')
        ->assertSet('paginators.page', 1)
        ->assertSee('No se encontraron productos')
        ->assertSee('colspan="4"', false);
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
    expect($product->category)->toBe('nacional');
    expect($product->brand)->toBe('bera');
    expect($product->first_value)->toBe('Clase A')
        ->and($product->second_value)->toBe('Tipo B')
        ->and($product->niv)->toBe('NIV-PRODUCTO-001');
});

test('products can be registered with the available brands', function (string $category, string $brand) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $component = Livewire::test('product-manager', ['formOnly' => true])
        ->assertSet('brand', 'bera')
        ->assertSee('Marca')
        ->assertSee('Bera')
        ->assertSee('Zontes')
        ->assertSee('Morini')
        ->set('description', 'Motocicleta de prueba con marca')
        ->set('category', $category)
        ->set('brand', $brand);

    if ($brand === 'zontes') {
        $component->set('secondValue', 'THK5B');
    }

    $component->call('save')->assertHasNoErrors();

    expect(Product::query()->sole())
        ->brand->toBe($brand)
        ->category->toBe($category);
})->with([
    ['nacional', 'bera'],
    ['importados', 'bera'],
    ['importados', 'zontes'],
    ['importados', 'morini'],
]);

test('the product brand can be loaded changed and reset', function () {
    $product = Product::factory()->create(['category' => 'importados', 'brand' => 'zontes']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSet('brand', 'zontes')
        ->set('brand', 'morini')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->brand)->toBe('morini');

    Livewire::test('product-manager')
        ->call('editProduct', $product->id)
        ->set('brand', 'zontes')
        ->call('closeForm')
        ->call('openCreateForm')
        ->assertSet('brand', 'bera');
});

test('product brands must be one of the available options', function (string $brand, string $rule) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true])
        ->set('description', 'Producto con marca inválida')
        ->set('brand', $brand)
        ->call('save')
        ->assertHasErrors(['brand' => $rule]);

    expect(Product::query()->count())->toBe(0);
})->with([
    ['otra', 'in'],
    ['', 'required'],
]);

test('view only users cannot change the product brand', function () {
    $product = Product::factory()->create(['brand' => 'bera']);
    $this->actingAs(User::factory()->create([
        'permissions' => [UserPermission::ViewProducts->value],
    ]));

    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSet('brand', 'bera')
        ->assertSet('formReadOnly', true)
        ->set('brand', 'morini')
        ->call('save')
        ->assertForbidden();

    expect($product->refresh()->brand)->toBe('bera');
});

test('the brand migration preserves existing national products', function () {
    $product = Product::factory()->create(['category' => 'nacional']);
    $migration = require database_path('migrations/2026_11_17_151420_add_brand_to_products_table.php');

    $migration->down();
    $migration->up();

    expect($product->refresh())
        ->brand->toBeNull()
        ->category->toBe('nacional');
});

test('product fields follow the requested order and codes are visible for bera and zontes', function (string $category, string $brand) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $component = Livewire::test('product-manager', ['formOnly' => true])
        ->set('category', $category)
        ->set('brand', $brand)
        ->assertSeeHtmlInOrder(['for="product-brand"', 'for="product-description"', 'for="product-category"']);

    foreach (['firstValue', 'secondValue', 'niv'] as $field) {
        if (in_array($brand, ['bera', 'zontes'], true)) {
            $component->assertSee('id="product-'.$field.'"', false);
        } else {
            $component->assertDontSee('id="product-'.$field.'"', false);
        }
    }

    $component->set('brand', 'bera')->assertSee('id="product-niv"', false)
        ->set('brand', 'zontes')->assertSee('id="product-niv"', false)
        ->set('brand', 'morini')->assertDontSee('id="product-niv"', false);
})->with(['nacional', 'importados'])->with(['bera', 'zontes', 'morini']);

test('the product list shows brand description and category without the bera fields', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->create(['brand' => 'bera']);
    Product::factory()->create(['brand' => 'zontes']);
    Product::factory()->create(['brand' => 'morini']);

    Livewire::test('product-manager')
        ->assertSeeHtmlInOrder(['>Marca</th>', '>Descripción</th>', '>Categoría</th>'])
        ->assertSee('>Bera</td>', false)
        ->assertSee('>Zontes</td>', false)
        ->assertSee('>Morini</td>', false)
        ->assertDontSee('>1ero</th>', false)
        ->assertDontSee('>2do</th>', false)
        ->assertDontSee('>NIV</th>', false);
});

test('switching to morini preserves existing bera fields', function () {
    $product = Product::factory()->create([
        'brand' => 'bera', 'first_value' => 'Primero', 'second_value' => 'Segundo', 'niv' => 'NIV-EXISTENTE',
    ]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->set('brand', 'morini')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh())
        ->first_value->toBe('Primero')
        ->second_value->toBe('Segundo')
        ->niv->toBe('NIV-EXISTENTE');
});

test('brand defaults and backfill assign bera without overwriting selected brands', function () {
    $defaultMigration = require database_path('migrations/2026_11_17_152300_set_default_brand_on_products_table.php');
    $backfillMigration = require database_path('migrations/2026_11_17_152258_backfill_products_brand.php');
    $defaultMigration->down();

    $unassigned = Product::factory()->create(['brand' => null]);
    $empty = Product::factory()->create(['brand' => '']);
    $imported = Product::factory()->create(['brand' => 'zontes', 'category' => 'importados']);

    $backfillMigration->up();
    $defaultMigration->up();

    expect($unassigned->refresh()->brand)->toBe('bera')
        ->and($empty->refresh()->brand)->toBe('bera')
        ->and($imported->refresh()->brand)->toBe('zontes');

    DB::table('products')->insert(['name' => 'Producto con marca predeterminada']);

    expect(Product::query()->where('name', 'Producto con marca predeterminada')->sole()->brand)->toBe('bera');
});

test('zontes codes are normalized combined and persisted automatically', function (string $model, string $code) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true])
        ->set('description', $model)
        ->set('brand', 'zontes')
        ->set('category', 'importados')
        ->assertSet('firstValue', 'LD3')
        ->set('firstValue', ' ld3 ')
        ->set('secondValue', ' '.strtolower($code).' ')
        ->assertSet('firstValue', 'LD3')
        ->assertSet('secondValue', $code)
        ->assertSet('niv', 'LD3'.$code)
        ->assertSeeHtml('readonly')
        ->set('niv', 'VALOR-MANIPULADO')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::query()->sole();

    expect($product)
        ->first_value->toBe('LD3')
        ->second_value->toBe($code)
        ->niv->toBe('LD3'.$code);

    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSet('firstValue', 'LD3')
        ->assertSet('secondValue', $code)
        ->assertSet('niv', 'LD3'.$code)
        ->set('secondValue', 'PWW6A')
        ->assertSet('niv', 'LD3PWW6A')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->niv)->toBe('LD3PWW6A');
})->with([
    ['150 X', 'THK5B'],
    ['368', 'THR6B'],
    ['703 F', 'PWW6A'],
]);

test('zontes requires a valid manufacturer and five character model code', function (string $first, string $second, string $invalidField) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true])
        ->set('description', 'Zontes de prueba')
        ->set('brand', 'zontes')
        ->set('firstValue', $first)
        ->set('secondValue', $second)
        ->call('save')
        ->assertHasErrors([$invalidField]);

    expect(Product::query()->count())->toBe(0);
})->with([
    ['', 'THK5B', 'firstValue'],
    ['ABC', 'THK5B', 'firstValue'],
    ['LD3', '', 'secondValue'],
    ['LD3', 'THK5', 'secondValue'],
    ['LD3', 'THK5BB', 'secondValue'],
    ['LD3', 'THK-B', 'secondValue'],
    ['LD3', 'THI5B', 'secondValue'],
    ['LD3', 'THO5B', 'secondValue'],
    ['LD3', 'THQ5B', 'secondValue'],
]);

test('the product form defaults to the national category', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true])
        ->assertSet('category', 'nacional')
        ->assertSee('Categoría')
        ->assertSee('Nacional')
        ->assertSee('Importados')
        ->assertSee('wire:model="category"', false);
});

test('products can be created with either category', function (string $category) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true])
        ->set('description', 'Producto por categoría')
        ->set('category', $category)
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::query()->sole()->category)->toBe($category);
})->with(['nacional', 'importados']);

test('the product category can be loaded edited and reset', function () {
    $product = Product::factory()->create(['category' => 'importados']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSet('category', 'importados')
        ->set('category', 'nacional')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->category)->toBe('nacional');

    Livewire::test('product-manager')
        ->call('editProduct', $product->id)
        ->set('category', 'importados')
        ->call('closeForm')
        ->call('openCreateForm')
        ->assertSet('category', 'nacional');
});

test('product categories must be one of the available options', function (string $category) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('product-manager', ['formOnly' => true])
        ->set('description', 'Producto inválido')
        ->set('category', $category)
        ->call('save')
        ->assertHasErrors(['category']);

    expect(Product::query()->count())->toBe(0);
})->with(['', 'otra']);

test('the category migration assigns national to existing products', function () {
    $product = Product::factory()->create();
    $migration = require database_path('migrations/2026_11_17_131409_add_category_to_products_table.php');

    $migration->down();
    $migration->up();

    expect($product->refresh()->category)->toBe('nacional');

    DB::table('products')->insert(['name' => 'Producto sin categoría explícita']);

    expect(Product::query()->where('name', 'Producto sin categoría explícita')->sole()->category)
        ->toBe('nacional');
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
    $serialRequest = MotorcycleSerialRequest::factory()->create();
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

test('a product form counts its linked certificates and opens the list filtered by that product', function () {
    $this->actingAs(User::factory()->create(['permissions' => [UserPermission::ViewProducts->value, UserPermission::ViewNivComplianceCertificates->value]]));
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $certificates = NivComplianceCertificate::factory()->count(2)->create(['product_id' => $product->id]);
    $otherCertificate = NivComplianceCertificate::factory()->create(['product_id' => $otherProduct->id]);
    $unlinked = NivComplianceCertificate::factory()->create();
    $filteredUrl = route('niv_compliance_certificates.index', ['product' => $product->id]);

    $component = Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSet('formReadOnly', true)->assertSee('Cantidad de constancias')->assertSee('Ver constancias')->assertSee($filteredUrl);
    expect($component->instance()->complianceCertificateCount())->toBe(2);

    $this->get($filteredUrl)->assertSuccessful()
        ->assertSee($certificates->first()->certificate_number)->assertSee($certificates->last()->certificate_number)
        ->assertDontSee($otherCertificate->certificate_number)->assertDontSee($unlinked->certificate_number);
});

test('a product with no linked certificates displays a zero count and an empty filtered list', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create();
    $unlinked = NivComplianceCertificate::factory()->create();
    $component = Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertSee('Cantidad de constancias')->assertSee('Ver constancias');
    expect($component->instance()->complianceCertificateCount())->toBe(0);
    $this->get(route('niv_compliance_certificates.index', ['product' => $product->id]))
        ->assertSuccessful()->assertSee('No se encontraron constancias.')->assertDontSee($unlinked->certificate_number);
    $this->get(route('products.create'))->assertSuccessful()->assertDontSee('Cantidad de constancias');
});

test('product viewers without certificate permission cannot see certificate counts or links', function () {
    $this->actingAs(User::factory()->create(['permissions' => [UserPermission::ViewProducts->value]]));
    $product = Product::factory()->create();
    NivComplianceCertificate::factory()->create(['product_id' => $product->id]);
    $component = Livewire::test('product-manager', ['formOnly' => true, 'productId' => $product->id])
        ->assertDontSee('Cantidad de constancias')->assertDontSee('Ver constancias');
    expect($component->instance()->complianceCertificateCount())->toBe(0);
    $this->get(route('niv_compliance_certificates.index', ['product' => $product->id]))->assertForbidden();
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

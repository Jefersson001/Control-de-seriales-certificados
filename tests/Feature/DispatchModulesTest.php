<?php

use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use App\Models\ProductReturn;
use App\Models\User;
use App\ReturnStatus;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

test('dispatch form shows the three-step status bar', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dispatches.create'))
        ->assertSuccessful()
        ->assertSee('Estado del despacho')
        ->assertSee('Borrador')
        ->assertSee('En proceso')
        ->assertSee('Hecho');
});

test('returns only list dispatched certificates and mark them as returned when finalized', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $dispatched = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000101',
        'status' => CertificateStatus::Dispatched,
    ]);
    $pending = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000102',
        'status' => CertificateStatus::PendingDispatch,
    ]);

    $this->actingAs($administrator);

    Livewire::test('return-form')
        ->assertSee('Estado de la devolución')
        ->assertSee('Borrador')
        ->assertSee('En proceso')
        ->assertSee('Hecho')
        ->set('name', 'WH/OUT/00055')
        ->call('addCertificate', $dispatched->id)
        ->call('save')
        ->assertRedirect();

    $return = ProductReturn::query()->sole();

    expect($return->creator->is($administrator))->toBeTrue()
        ->and($return->lines()->count())->toBe(1)
        ->and($return->status)->toBe(ReturnStatus::Draft);

    Livewire::test('return-form', ['returnId' => $return->id])
        ->call('openFinalizeConfirmation')
        ->assertSet('showFinalizeConfirmation', true)
        ->call('finalize')
        ->assertRedirect(route('returns.edit', $return));

    expect($return->refresh()->status)->toBe(ReturnStatus::Done)
        ->and($return->finalized_at)->not->toBeNull()
        ->and($return->return_date?->toDateString())->toBe(now()->toDateString())
        ->and($dispatched->refresh()->status)->toBe(CertificateStatus::Returned)
        ->and($pending->refresh()->status)->toBe(CertificateStatus::PendingDispatch);
});

test('finalizing a dispatch does not create a return automatically', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $first = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000001']);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('name', 'WH/OUT/00045')
        ->call('addCertificate', $first->id)
        ->call('save')
        ->assertRedirect();

    $dispatch = Dispatch::query()->sole();

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->call('finalize')
        ->assertRedirect(route('dispatches.edit', $dispatch));

    expect($first->refresh()->status)->toBe(CertificateStatus::Dispatched)
        ->and(ProductReturn::query()->count())->toBe(0);
});

test('returns list only shows manually created returns', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000101',
        'status' => CertificateStatus::Dispatched,
    ]);

    $this->actingAs($administrator);

    Livewire::test('return-list')
        ->assertSuccessful()
        ->assertSee('No se encontraron devoluciones')
        ->assertDontSee('8YZC7MCC0TD000101');

    ProductReturn::factory()->create([
        'name' => 'RET-0001',
        'created_by' => $administrator->id,
    ])->lines()->create([
        'ms_certificado_id' => MsCertificado::query()->sole()->id,
    ]);

    Livewire::test('return-list')
        ->assertSee('RET-0001')
        ->assertDontSee('8YZC7MCC0TD000101');
});

test('a dispatch selects pending nivs and marks them as dispatched when finalized', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $first = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000001']);
    $second = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000002']);
    $untouched = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000003']);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('name', 'WH/OUT/00045')
        ->call('addCertificate', $first->id)
        ->call('addCertificate', $second->id)
        ->call('save')
        ->assertRedirect();

    $dispatch = Dispatch::query()->sole();

    expect($dispatch->creator->is($administrator))->toBeTrue()
        ->and($dispatch->lines()->count())->toBe(2)
        ->and($dispatch->status)->toBe(DispatchStatus::Draft);

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->call('openFinalizeConfirmation')
        ->assertSet('showFinalizeConfirmation', true)
        ->call('finalize')
        ->assertRedirect(route('dispatches.edit', $dispatch));

    expect($dispatch->refresh()->status)->toBe(DispatchStatus::Done)
        ->and($dispatch->finalized_at)->not->toBeNull()
        ->and($dispatch->dispatch_date?->toDateString())->toBe(now()->toDateString())
        ->and($first->refresh()->status)->toBe(CertificateStatus::Dispatched)
        ->and($second->refresh()->status)->toBe(CertificateStatus::Dispatched)
        ->and($untouched->refresh()->status)->toBe(CertificateStatus::PendingDispatch);
});

test('a completed dispatch cannot be modified', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $record = MsCertificado::factory()->create();
    $dispatch = Dispatch::query()->create([
        'name' => 'WH/OUT/00046',
        'dispatch_date' => '2026-08-10',
        'created_by' => $administrator->id,
        'status' => DispatchStatus::Done,
        'finalized_at' => now(),
    ]);
    $dispatch->lines()->create(['ms_certificado_id' => $record->id]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->call('removeCertificate', $record->id)
        ->assertForbidden();
});

test('deleting a dispatch removes it with its lines', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $dispatch = Dispatch::query()->create([
        'name' => 'WH/OUT/00047',
        'created_by' => $administrator->id,
    ]);
    $dispatch->lines()->create(['ms_certificado_id' => MsCertificado::factory()->create()->id]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-list')
        ->call('openDeleteConfirmation', $dispatch->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteDispatch')
        ->assertSet('showDeleteConfirmation', false);

    expect(Dispatch::query()->find($dispatch->id))->toBeNull()
        ->and($dispatch->lines()->count())->toBe(0);
});

test('deleting a return removes it with its lines', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $return = ProductReturn::query()->create([
        'name' => 'RET-0002',
        'created_by' => $administrator->id,
    ]);
    $return->lines()->create(['ms_certificado_id' => MsCertificado::factory()->create()->id]);

    $this->actingAs($administrator);

    Livewire::test('return-list')
        ->call('openDeleteConfirmation', $return->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteReturn')
        ->assertSet('showDeleteConfirmation', false);

    expect(ProductReturn::query()->find($return->id))->toBeNull()
        ->and($return->lines()->count())->toBe(0);
});

test('users without delete permission cannot delete dispatches or returns', function () {
    $viewer = User::factory()->create([
        'permissions' => [UserPermission::ViewDispatches->value, UserPermission::ViewReturns->value],
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00048']);
    $return = ProductReturn::query()->create(['name' => 'RET-0003']);

    $this->actingAs($viewer);

    Livewire::test('dispatch-list')
        ->call('openDeleteConfirmation', $dispatch->id)
        ->assertForbidden();

    Livewire::test('return-list')
        ->call('openDeleteConfirmation', $return->id)
        ->assertForbidden();

    expect(Dispatch::query()->find($dispatch->id))->not->toBeNull()
        ->and(ProductReturn::query()->find($return->id))->not->toBeNull();
});

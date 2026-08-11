<?php

use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use App\Models\User;
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

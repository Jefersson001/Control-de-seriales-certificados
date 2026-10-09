<?php

use App\Actions\Certificates\RecoverCertificateEmissionDates;
use App\Jobs\RecoverEmissionDatesBatch;
use App\Models\CertificateDocument;
use App\Models\MsCertificado;
use App\Models\User;
use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\UserPermission;
use App\UserRole;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    foreach (['VISIBLE' => '2026-08-31', 'HIDDEN' => '2026-09-01'] as $code => $date) {
        $management = VehicleIdentificationRecordManagement::factory()->create(['request_date' => $date]);
        $document = CertificateDocument::query()->create([
            'control_number' => $code, 'file_name' => "{$code}.pdf", 'original_file_name' => "{$code}.pdf",
            'file_path' => "{$code}.pdf", 'issued_on' => $date,
        ]);
        $document->forceFill(['created_at' => "{$date} 23:59:59"])->save();
        $document->managements()->attach($management);
        MsCertificado::factory()->create(['no' => $code, 'codigo' => $code, 'issued_on' => $date, 'created_at' => "{$date} 23:59:59"]);
    }
});

test('each module filters by the selected date and includes the entire last day', function (string $component, string $field) {
    Livewire::test($component)
        ->assertDontSeeHtml('wire:model.live="dateFrom"')
        ->set('dateField', $field)
        ->assertSee('Desde')->assertSee('Hasta')
        ->set('dateFrom', '2026-08-01')->set('dateTo', '2026-08-31')
        ->assertHasNoErrors()->assertSee('VISIBLE')->assertDontSee('HIDDEN')
        ->set('dateField', '')->assertSet('dateFrom', '')->assertSet('dateTo', '')->assertSee('HIDDEN');
})->with(['certificate-document-list', 'certificate-master'])->with(['created_at', 'request_date', 'issued_on']);

test('date filters accept one bound and reject inverted or invalid ranges', function (string $component) {
    Livewire::test($component)->set('dateField', 'issued_on')
        ->set('dateTo', '2026-08-31')->assertSee('VISIBLE')->assertDontSee('HIDDEN')
        ->set('dateFrom', '2026-09-01')->assertHasErrors(['dateTo' => 'after_or_equal'])
        ->set('dateTo', '2026-09-02')->assertHasNoErrors()->assertSee('HIDDEN')->assertDontSee('VISIBLE')
        ->set('dateFrom', 'invalid')->assertHasErrors(['dateFrom' => 'date_format']);
})->with(['certificate-document-list', 'certificate-master']);

test('grouped counts and deletion respect the captured date filter', function () {
    $component = Livewire::test('certificate-master')->set('dateField', 'issued_on')
        ->set('dateTo', '2026-08-31')->set('recordFilter', 'group_by_certificate')
        ->assertSee('VISIBLE')->assertDontSee('HIDDEN');
    expect($component->get('certificateGroupCounts'))->toBe(['VISIBLE' => 1]);
    $component->call('openDeleteConfirmation')->assertSet('deleteCount', 1)
        ->set('dateField', 'created_at')->call('deleteRecords')->assertHasNoErrors();
    expect(MsCertificado::query()->pluck('codigo')->all())->toBe(['HIDDEN']);
});

test('completed management dates can be filled only once and cannot change status', function () {
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'status' => VehicleIdentificationRecordManagementStatus::Done, 'request_date' => null,
    ]);
    $first = Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id]);
    $stale = Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id]);
    $first->assertSee('Guardar')->call('save')->assertHasErrors(['requestDate' => 'required'])
        ->set('status', VehicleIdentificationRecordManagementStatus::Draft->value)
        ->set('requestDate', '2026-08-10')->call('save')->assertHasNoErrors();
    expect($management->refresh()->request_date->format('Y-m-d'))->toBe('2026-08-10')
        ->and($management->status)->toBe(VehicleIdentificationRecordManagementStatus::Done);
    $stale->set('requestDate', '2026-08-11')->call('save')->assertForbidden();
    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSee('Solo lectura')->assertSeeHtml('type="date" disabled');
});

test('production migrations dispatch background recovery but rollbacks do not', function () {
    Queue::fake();
    config(['queue.default' => 'sync']);
    app()->detectEnvironment(fn () => 'production');
    try {
        event(new MigrationsEnded('down'));
        Queue::assertNothingPushed();
        event(new MigrationsEnded('up'));
        Queue::assertPushed(RecoverEmissionDatesBatch::class, fn ($job) => $job->connection === 'database');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

test('the recovery job resumes from its saved position and stops when complete', function () {
    Queue::fake();
    $recovery = $this->mock(RecoverCertificateEmissionDates::class);
    $recovery->shouldReceive('handleBatch')->once()->with(0, 12)->andReturn([
        'updated' => 1, 'skipped' => 0, 'sourceIndex' => 0, 'afterId' => 15, 'hasMore' => true,
    ]);
    (new RecoverEmissionDatesBatch(0, 12))->handle($recovery);
    Queue::assertPushed(RecoverEmissionDatesBatch::class, fn ($job) => $job->sourceIndex === 0 && $job->afterId === 15);
    Queue::fake();
    $recovery->shouldReceive('handleBatch')->once()->with(1, 15)->andReturn([
        'updated' => 0, 'skipped' => 0, 'sourceIndex' => 2, 'afterId' => 0, 'hasMore' => false,
    ]);
    (new RecoverEmissionDatesBatch(1, 15))->handle($recovery);
    Queue::assertNothingPushed();
});

test('exports include only rows matching the date range', function () {
    $response = $this->get(route('certificates.export', [
        'dateField' => 'request_date', 'dateFrom' => '2026-08-01', 'dateTo' => '2026-08-31',
    ]))->assertSuccessful();
    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();
    expect($rows)->toHaveCount(2)->and($rows[1][0])->toBe('VISIBLE');
});

test('view only users cannot fill a blank date on a completed management', function () {
    $user = User::factory()->create(['permissions' => [UserPermission::ViewVehicleIdentificationRecordManagement->value]]);
    $management = VehicleIdentificationRecordManagement::factory()->create([
        'status' => VehicleIdentificationRecordManagementStatus::Done, 'request_date' => null,
    ]);
    $this->actingAs($user);
    Livewire::test('vehicle-identification-record-management-form', ['managementId' => $management->id])
        ->assertSee('Solo lectura')->set('requestDate', '2026-08-10')->call('save')->assertForbidden();
    expect($management->refresh()->request_date)->toBeNull();
});

test('a recovery batch advances past missing files and eventually stops', function () {
    Storage::fake('local');
    $certificate = VehicleIdentificationRecordManagementCertificate::factory()->create([
        'file_path' => 'does-not-exist.pdf',
    ]);
    $recovery = app(RecoverCertificateEmissionDates::class);
    $first = $recovery->handleBatch();
    expect($first['afterId'])->toBe($certificate->id)->and($first['skipped'])->toBe(1);
    $second = $recovery->handleBatch($first['sourceIndex'], $first['afterId']);
    expect($second['sourceIndex'])->toBe(1);
    $third = $recovery->handleBatch($second['sourceIndex'], $second['afterId']);
    expect($third['hasMore'])->toBeFalse();
});

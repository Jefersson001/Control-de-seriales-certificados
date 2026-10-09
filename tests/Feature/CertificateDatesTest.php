<?php

use App\Models\CertificateDocument;
use App\Models\MsCertificado;
use App\Models\User;
use App\Models\VehicleIdentificationRecordManagement;
use App\UserPermission;
use Livewire\Livewire;

beforeEach(function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewCertificateDocuments->value,
            UserPermission::ViewCertificates->value,
        ],
    ]);
    $this->actingAs($user);
    $this->management = VehicleIdentificationRecordManagement::factory()->create([
        'request_date' => '2026-08-01',
    ]);
    $this->document = CertificateDocument::query()->create([
        'uploaded_by' => $user->id,
        'control_number' => 'DG-NIV-RG5-FECHAS-PC',
        'file_name' => 'DG-NIV-RG5-FECHAS-PC.pdf',
        'original_file_name' => 'fechas.pdf',
        'file_path' => 'certificate-documents/fechas.pdf',
        'created_at' => '2026-08-06 10:30:00',
    ]);
    $this->document->forceFill(['created_at' => '2026-08-06 10:30:00'])->save();
    $this->document->managements()->attach($this->management);
    $this->serial = MsCertificado::factory()->create([
        'codigo' => $this->document->control_number,
        'created_at' => '2026-08-07 11:45:00',
    ]);
});

test('certificate documents preserve the automatic date and show the manual certification date', function () {
    Livewire::test('certificate-document-list')
        ->assertSee('Fecha')
        ->assertSee('Fecha de certificación')
        ->assertSee('06/08/2026 10:30')
        ->assertSee('01/08/2026');

    expect($this->document->refresh()->created_at->format('Y-m-d H:i:s'))->toBe('2026-08-06 10:30:00');
});

test('the certificate master shows the record creation date and its manual certification date', function () {
    Livewire::test('certificate-master')
        ->assertSee('Fecha de creación')
        ->assertSee('Fecha de certificación')
        ->assertSee('07/08/2026 11:45')
        ->assertSee('01/08/2026');

    expect($this->serial->refresh()->created_at->format('Y-m-d H:i:s'))->toBe('2026-08-07 11:45:00');
});

test('the manual certification date reflects changes in its related management', function (string $component) {
    $this->management->update(['request_date' => '2026-08-02']);

    Livewire::test($component)
        ->assertSee('02/08/2026')
        ->assertDontSee('01/08/2026');

    $this->management->update(['request_date' => null]);

    Livewire::test($component)
        ->assertSee('Sin registrar')
        ->assertDontSee('02/08/2026');
})->with(['certificate-document-list', 'certificate-master']);

test('certificates imported without management do not invent a manual certification date', function () {
    $this->document->managements()->detach();

    Livewire::test('certificate-document-list')
        ->assertSee('Sin registrar')
        ->assertSee('06/08/2026 10:30');

    Livewire::test('certificate-master')
        ->assertSee('Sin registrar')
        ->assertSee('07/08/2026 11:45');
});

test('the master displays dates when grouped by certificate', function () {
    MsCertificado::factory()->create([
        'codigo' => $this->document->control_number,
        'created_at' => '2026-08-08 12:00:00',
    ]);

    Livewire::test('certificate-master')
        ->set('recordFilter', 'group_by_certificate')
        ->assertSee('Fecha de creación')
        ->assertSee('Fecha de certificación')
        ->assertSee('07/08/2026 11:45')
        ->assertSee('01/08/2026')
        ->assertDontSee('08/08/2026 12:00');
});

test('certificates with multiple managements display each distinct manual date', function (string $component) {
    $otherManagement = VehicleIdentificationRecordManagement::factory()->create([
        'request_date' => '2026-08-03',
    ]);
    $this->document->managements()->attach($otherManagement);

    Livewire::test($component)
        ->assertSee('01/08/2026')
        ->assertSee('03/08/2026');
})->with(['certificate-document-list', 'certificate-master']);

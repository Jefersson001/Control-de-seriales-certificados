<?php

use App\CertificateStatus;
use App\Models\MsCertificado;
use App\Models\User;
use App\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests cannot access the certificate master', function () {
    $this->get(route('certificates.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access the certificate master', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);

    $this->actingAs($user)
        ->get(route('certificates.index'))
        ->assertSuccessful()
        ->assertSee('Maestro Seriales Certificados')
        ->assertSee('Buscar por NO, marca, modelo, NIV o código');
});

test('the certificate master is available from the system menu', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(route('certificates.index'))
        ->assertSee('Maestro Seriales Certificados');
});

test('certificates can be searched in real time', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'CERT-ENCONTRADO',
        'marca' => 'Marca objetivo',
        'niv' => 'NIV-OBJETIVO',
    ]);
    MsCertificado::factory()->create([
        'no' => 'CERT-OCULTO',
        'marca' => 'Marca diferente',
        'niv' => 'NIV-DIFERENTE',
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->set('search', 'OBJETIVO')
        ->assertSee('CERT-ENCONTRADO')
        ->assertDontSee('CERT-OCULTO');
});

test('users can change how many records are displayed per page', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->count(25)->create();

    $this->actingAs($user);

    $component = Livewire::test('certificate-master')
        ->assertSet('perPage', 10)
        ->assertSee('Registros por página')
        ->set('perPage', 25)
        ->assertSet('perPage', 25);

    expect($component->get('certificates')->count())->toBe(25);

    $component
        ->set('perPage', 3000)
        ->assertSet('perPage', 3000)
        ->set('perPage', 20000)
        ->assertSet('perPage', 10000)
        ->set('perPage', 0)
        ->assertSet('perPage', 1);
});

test('certificates can be filtered by duplicated niv values', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'DUPLICADO-UNO',
        'niv' => 'NIV-REPETIDO',
    ]);
    MsCertificado::factory()->create([
        'no' => 'DUPLICADO-DOS',
        'niv' => 'NIV-REPETIDO',
    ]);
    MsCertificado::factory()->create([
        'no' => 'REGISTRO-UNICO',
        'niv' => 'NIV-UNICO',
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('Todos los registros')
        ->assertSee('NIV duplicados')
        ->set('recordFilter', 'duplicates')
        ->assertSee('DUPLICADO-UNO')
        ->assertSee('DUPLICADO-DOS')
        ->assertDontSee('REGISTRO-UNICO');
});

test('certificates can be filtered by dispatch status', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'POR-DESPACHAR',
        'status' => CertificateStatus::PendingDispatch,
    ]);
    MsCertificado::factory()->create([
        'no' => 'DESPACHADO',
        'status' => CertificateStatus::Dispatched,
    ]);
    MsCertificado::factory()->create([
        'no' => 'DEVUELTO',
        'status' => CertificateStatus::Returned,
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('Todos los status')
        ->assertSee('Por despachar')
        ->assertSee('Despachado')
        ->assertSee('Devuelto')
        ->set('statusFilter', CertificateStatus::Dispatched->value)
        ->assertSee('DESPACHADO')
        ->assertDontSee('POR-DESPACHAR')
        ->assertDontSee('DEVUELTO');
});

test('certificates can be filtered by unique niv values', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'DUPLICADO-UNO',
        'niv' => 'NIV-REPETIDO',
    ]);
    MsCertificado::factory()->create([
        'no' => 'DUPLICADO-DOS',
        'niv' => 'NIV-REPETIDO',
    ]);
    MsCertificado::factory()->create([
        'no' => 'REGISTRO-UNICO',
        'niv' => 'NIV-UNICO',
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('NIV únicos')
        ->set('recordFilter', 'unique_niv')
        ->assertSee('REGISTRO-UNICO')
        ->assertDontSee('DUPLICADO-UNO')
        ->assertDontSee('DUPLICADO-DOS');
});

test('certificates can be grouped by certificate number', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'REGISTRO-B',
        'codigo' => 'CERTIFICADO-B',
    ]);
    MsCertificado::factory()->create([
        'no' => 'REGISTRO-A',
        'codigo' => 'CERTIFICADO-A',
    ]);
    MsCertificado::factory()->create([
        'no' => 'REGISTRO-A-DOS',
        'codigo' => 'CERTIFICADO-A',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('certificate-master')
        ->assertSee('Agrupar por No Certificado')
        ->set('perPage', 2)
        ->set('recordFilter', 'group_by_certificate')
        ->assertSee('No Certificado')
        ->assertSee('Cantidad')
        ->assertSeeInOrder([
            'CERTIFICADO-A',
            'CERTIFICADO-B',
        ])
        ->assertDontSee('REGISTRO-A')
        ->assertDontSee('REGISTRO-A-DOS')
        ->assertDontSee('REGISTRO-B');

    expect($component->get('certificateGroupCounts'))->toBe([
        'CERTIFICADO-A' => 2,
        'CERTIFICADO-B' => 1,
    ]);
    expect($component->get('certificates')->getCollection()->pluck('aggregate', 'codigo')->all())->toBe([
        'CERTIFICADO-A' => 2,
        'CERTIFICADO-B' => 1,
    ]);
});

test('certificates can be filtered by niv values with an invalid length', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'NIV-CORTO',
        'niv' => '1234567890123456',
    ]);
    MsCertificado::factory()->create([
        'no' => 'NIV-LARGO',
        'niv' => '123456789012345678',
    ]);
    MsCertificado::factory()->create([
        'no' => 'NIV-CORRECTO',
        'niv' => '12345678901234567',
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('NIV con longitud inválida')
        ->set('recordFilter', 'invalid_niv_length')
        ->assertSee('NIV-CORTO')
        ->assertSee('NIV-LARGO')
        ->assertDontSee('NIV-CORRECTO');
});

test('certificates can be filtered by any invalid certificate field', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    $validAttributes = [
        'marca' => 'BERA',
        'modelo' => 'BR 150',
        'tipo' => 'MOTOCICLETA',
        'fabricacion' => '2026',
        'anio' => 2026,
        'codigo' => 'DG-NIV-RG8-0005-PC',
    ];
    MsCertificado::factory()->create([
        ...$validAttributes,
        'no' => '1',
        'niv' => '8YZC7MCC0TD033213',
    ]);
    MsCertificado::factory()->create([
        ...$validAttributes,
        'no' => 'NO INVALIDO',
        'niv' => '8YZC7MCC2TD033214',
    ]);
    MsCertificado::factory()->create([
        ...$validAttributes,
        'no' => '3',
        'anio' => 0,
        'niv' => '8YZC7MCC4TD033215',
    ]);
    MsCertificado::factory()->create([
        ...$validAttributes,
        'no' => '4',
        'marca' => '',
        'niv' => '8YZC7MCC6TD033216',
    ]);
    MsCertificado::factory()->create([
        ...$validAttributes,
        'no' => '5',
        'niv' => '8YZC7MCCI0D033217',
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('Registros inválidos')
        ->set('recordFilter', 'invalid_records')
        ->assertSee('NO INVALIDO')
        ->assertSee('8YZC7MCC4TD033215')
        ->assertSee('8YZC7MCC6TD033216')
        ->assertSee('8YZC7MCCI0D033217')
        ->assertDontSee('8YZC7MCC0TD033213');
});

test('the certificate master shows ten records per page', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);

    MsCertificado::factory()->create(['no' => 'CERT-PRIMERO']);

    foreach (range(2, 11) as $number) {
        MsCertificado::factory()->create([
            'no' => sprintf('CERT-%06d', $number),
        ]);
    }

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('CERT-000011')
        ->assertDontSee('CERT-PRIMERO');
});

test('the certificate master does not expose create or edit actions', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create();

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertDontSee('Crear certificado')
        ->assertDontSee('Editar')
        ->assertDontSee('Eliminar');
});

test('the certificate master exposes import and export actions', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewCertificates->value,
            UserPermission::ImportCertificates->value,
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertSee('Importar Excel')
        ->assertDontSee('Importar PDF')
        ->assertSee('Exportar data')
        ->assertSee(route('certificates.export'));
});

test('users without permission cannot see or access the certificate master', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(route('certificates.index'));

    $this->actingAs($user)
        ->get(route('certificates.index'))
        ->assertForbidden();
});

test('users with consultation permission can export but cannot import', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertDontSee('Importar Excel')
        ->assertDontSee('Importar PDF')
        ->assertSee('Exportar data');
});

test('authorized users must confirm before deleting filtered records', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewCertificates->value,
            UserPermission::DeleteCertificates->value,
        ],
    ]);
    $matchingCertificate = MsCertificado::factory()->create([
        'niv' => 'NIV-PARA-ELIMINAR',
    ]);
    $otherCertificate = MsCertificado::factory()->create([
        'niv' => 'NIV-PARA-CONSERVAR',
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->set('search', 'NIV-PARA-ELIMINAR')
        ->call('openDeleteConfirmation')
        ->assertSet('showDeleteConfirmation', true)
        ->assertSet('deleteCount', 1)
        ->assertSee('¿Estás seguro de que quieres eliminar?')
        ->assertSee('Sí, eliminar');

    $this->assertModelExists($matchingCertificate);
    $this->assertModelExists($otherCertificate);

    Livewire::test('certificate-master')
        ->set('search', 'NIV-PARA-ELIMINAR')
        ->call('openDeleteConfirmation')
        ->call('deleteRecords')
        ->assertSet('showDeleteConfirmation', false)
        ->assertSee('Se eliminaron 1 registros correctamente.');

    $this->assertModelMissing($matchingCertificate);
    $this->assertModelExists($otherCertificate);
});

test('deleting with the duplicate filter only removes duplicated niv records', function () {
    $user = User::factory()->create([
        'permissions' => [
            UserPermission::ViewCertificates->value,
            UserPermission::DeleteCertificates->value,
        ],
    ]);
    $firstDuplicate = MsCertificado::factory()->create(['niv' => 'NIV-REPETIDO']);
    $secondDuplicate = MsCertificado::factory()->create(['niv' => 'NIV-REPETIDO']);
    $uniqueCertificate = MsCertificado::factory()->create(['niv' => 'NIV-UNICO']);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->set('recordFilter', 'duplicates')
        ->call('openDeleteConfirmation')
        ->assertSet('deleteCount', 2)
        ->assertSee('identificados por el filtro de NIV duplicados')
        ->call('deleteRecords')
        ->assertSee('Se eliminaron 2 registros correctamente.');

    $this->assertModelMissing($firstDuplicate);
    $this->assertModelMissing($secondDuplicate);
    $this->assertModelExists($uniqueCertificate);
});

test('users without delete permission do not see the delete action', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);

    $this->actingAs($user);

    Livewire::test('certificate-master')
        ->assertDontSee('Eliminar registros')
        ->call('openDeleteConfirmation')
        ->assertForbidden();
});

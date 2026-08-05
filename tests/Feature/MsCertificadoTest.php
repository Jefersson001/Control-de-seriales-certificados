<?php

use App\Models\MsCertificado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the certificates table has the required structure', function () {
    expect(Schema::hasColumns('ms_certificados', [
        'id',
        'no',
        'marca',
        'modelo',
        'tipo',
        'fabricacion',
        'anio',
        'niv',
        'codigo',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('certificates can be stored', function () {
    $certificate = MsCertificado::factory()->create([
        'no' => 'CERT-000001',
        'marca' => 'Toyota',
        'modelo' => 'Corolla',
        'tipo' => 'Sedán',
        'fabricacion' => 'Importado',
        'anio' => 2025,
        'niv' => '1HGBH41JXMN109186',
        'codigo' => 'COD-001',
    ]);

    $this->assertDatabaseHas('ms_certificados', [
        'id' => $certificate->id,
        'no' => 'CERT-000001',
        'anio' => 2025,
        'niv' => '1HGBH41JXMN109186',
        'codigo' => 'COD-001',
    ]);
});

<?php

use App\Actions\Certificates\ImportCertificates;
use App\Models\MsCertificado;
use App\Models\User;
use App\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

test('an excel file imports every valid row including duplicate niv values', function () {
    $filePath = createCertificatesWorkbook([
        ['1', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', 2026, 'NIV-DUPLICADO', 'COD-001'],
        ['2', 'BERA', 'BR 200', 'MOTOCICLETA', '2026', 2026, 'NIV-DUPLICADO', 'COD-002'],
        ['', 'Fila incompleta', '', '', '', '', '', ''],
    ]);

    $result = app(ImportCertificates::class)->handle($filePath);

    expect($result)->toBe([
        'imported' => 2,
        'skipped' => 1,
        'skippedRows' => [[
            'row' => 4,
            'reason' => 'Falta el campo NO. Falta el campo Modelo. Falta el campo Tipo. Falta el campo Fabricación. Falta el campo NIV. Falta el campo Código. Falta el campo Año.',
            'values' => ['', 'Fila incompleta', '', '', '', '', '', ''],
        ]],
    ]);

    expect(MsCertificado::query()->where('niv', 'NIV-DUPLICADO')->count())->toBe(2);

    @unlink($filePath);
});

test('the import rejects workbooks with unexpected headers', function () {
    $filePath = createCertificatesWorkbook(
        [['1', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', 2026, 'NIV-001', 'COD-001']],
        ['Columna incorrecta'],
    );

    expect(fn () => app(ImportCertificates::class)->handle($filePath))
        ->toThrow(RuntimeException::class, 'Las columnas deben ser');

    @unlink($filePath);
});

test('authenticated users can export certificates to xlsx', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create();

    $response = $this->actingAs($user)->get(route('certificates.export'));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('the export only includes certificates matching the current search', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewCertificates->value],
    ]);
    MsCertificado::factory()->create([
        'no' => 'CERT-ENCONTRADO',
        'niv' => 'NIV-BUSCADO-123',
    ]);
    MsCertificado::factory()->create([
        'no' => 'CERT-OCULTO',
        'niv' => 'NIV-DIFERENTE-456',
    ]);

    $response = $this->actingAs($user)->get(route('certificates.export', [
        'search' => 'NIV-BUSCADO-123',
    ]));

    $response->assertSuccessful();

    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $exportedRows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $exportedRows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    expect($exportedRows)->toHaveCount(2)
        ->and($exportedRows[1][0])->toBe('CERT-ENCONTRADO')
        ->and($exportedRows[1][6])->toBe('NIV-BUSCADO-123');
});

test('guests cannot export certificates', function () {
    $this->get(route('certificates.export'))
        ->assertRedirect(route('login'));
});

/**
 * @param  list<list<mixed>>  $rows
 * @param  list<mixed>  $headers
 */
function createCertificatesWorkbook(
    array $rows,
    array $headers = ['NO', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV', 'Código'],
): string {
    $filePath = tempnam(sys_get_temp_dir(), 'certificate-import-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($filePath);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $filePath;
}

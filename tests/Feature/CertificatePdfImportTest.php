<?php

use App\Actions\Certificates\ExportPdfAnalysis;
use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\Models\MsCertificado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

test('pdf text is converted to certificate records and duplicate niv values are omitted', function () {
    MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD033213',
    ]);

    $text = <<<'PDF'
NÚMERO DE CONTROL.: DG-NIV-RG5-0175-PC

# Marca 	Modelo 	Tipo Fabricación Modelo 	NIV
1  BERA 	BR 150 BRF MOTOCICLETA 2026 2026 8YZC7MCC0TD033213
2  BERA 	BR 650 XCAPE ENDURO 2025 2025 8YZC7MCC2TD033214
3  BERA 	BR 650 XCAPE ENDURO 2025 2025 8YZC7MCC2TD033214
PDF;

    $result = app(ImportCertificatesFromPdf::class)->parseText($text);

    expect($result['controlNumber'])->toBe('DG-NIV-RG5-0175-PC')
        ->and($result['duplicateCount'])->toBe(2)
        ->and($result['duplicateRows'])->toHaveCount(2)
        ->and($result['duplicateRows'][0]['niv'])->toBe('8YZC7MCC2TD033214')
        ->and($result['duplicateRows'][0]['reason'])->toBe('El NIV está repetido dentro del mismo PDF.')
        ->and($result['duplicateRows'][1]['niv'])->toBe('8YZC7MCC0TD033213')
        ->and($result['duplicateRows'][1]['reason'])->toBe('El NIV ya existe en la base de datos.')
        ->and($result['invalidCount'])->toBe(0)
        ->and($result['records'])->toHaveCount(1)
        ->and($result['records'][0])->toBe([
            'no' => '2',
            'marca' => 'BERA',
            'modelo' => 'BR 650 XCAPE',
            'tipo' => 'ENDURO',
            'fabricacion' => '2025',
            'anio' => 2025,
            'niv' => '8YZC7MCC2TD033214',
            'codigo' => 'DG-NIV-RG5-0175-PC',
        ]);
});

test('pdf records are inserted only when their niv is not registered', function () {
    $records = [[
        'no' => '1',
        'marca' => 'BERA',
        'modelo' => 'BR 150 BRF',
        'tipo' => 'MOTOCICLETA',
        'fabricacion' => '2026',
        'anio' => 2026,
        'niv' => '8YZC7MCC0TD033213',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]];

    $importer = app(ImportCertificatesFromPdf::class);

    expect($importer->store($records))->toBe([
        'imported' => 1,
        'skipped' => 0,
    ])->and($importer->store($records))->toBe([
        'imported' => 0,
        'skipped' => 1,
    ]);

    expect(MsCertificado::query()->where('niv', '8YZC7MCC0TD033213')->count())->toBe(1);
});

test('selected ready duplicate and invalid pdf categories can all be imported', function () {
    MsCertificado::factory()->create(['niv' => 'NIV-YA-REGISTRADO']);

    $analysis = [
        'controlNumber' => 'DG-NIV-RG8-0005-PC',
        'records' => [[
            'no' => '1',
            'marca' => 'BERA',
            'modelo' => 'BR 150',
            'tipo' => 'MOTOCICLETA',
            'fabricacion' => '2026',
            'anio' => 2026,
            'niv' => '8YZC7MCC0TD033213',
            'codigo' => 'DG-NIV-RG8-0005-PC',
        ]],
        'duplicateRows' => [[
            'no' => '2',
            'niv' => 'NIV-YA-REGISTRADO',
            'values' => ['2', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', '2026', 'NIV-YA-REGISTRADO'],
            'reason' => 'El NIV ya existe en la base de datos.',
        ]],
        'invalidRows' => [[
            'page' => 3,
            'no' => '3',
            'niv' => 'NIV-CORTO',
            'values' => ['3', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', 'AÑO MALO', 'NIV-CORTO'],
            'reason' => 'El NIV debe tener 17 caracteres válidos.',
        ]],
    ];

    $result = app(ImportCertificatesFromPdf::class)
        ->storeSelection($analysis, true, true, true);

    expect($result)->toBe([
        'imported' => 3,
        'ready' => 1,
        'duplicates' => 1,
        'invalid' => 1,
        'skipped' => 0,
    ])->and(MsCertificado::query()->where('niv', 'NIV-YA-REGISTRADO')->count())->toBe(2)
        ->and(MsCertificado::query()->where('niv', 'NIV-CORTO')->value('anio'))->toBe(0);
});

test('pdf parsing requires a control number and valid table rows', function () {
    expect(fn () => app(ImportCertificatesFromPdf::class)->parseText('Documento sin tabla'))
        ->toThrow(RuntimeException::class, 'No se encontró el Número de Control');
});

test('pdf preview remains available when every detected row is invalid', function () {
    $text = <<<'PDF'
NÚMERO DE CONTROL.: DG-NIV-RG5-0175-PC
1  FILA QUE NO TIENE TODAS LAS COLUMNAS
PDF;

    $result = app(ImportCertificatesFromPdf::class)->parseText($text);

    expect($result['records'])->toBeEmpty()
        ->and($result['invalidCount'])->toBe(1)
        ->and($result['invalidRows'])->toHaveCount(1);
});

test('positioned pdf tables are read from their headers instead of fixed coordinates', function (
    array $headerPositions,
    array $valuePositions,
) {
    $positionedText = [];
    $headers = ['#', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Modelo', 'NIV'];
    $values = ['27', 'ACME', 'X 200 TURBO', 'VEHÍCULO ESPECIAL', '2025', '2026', '8YZC7MCC2TD033214'];

    foreach ($headers as $index => $header) {
        $positionedText[] = [[1, 0, 0, 1, $headerPositions[$index], 700], $header];
    }

    foreach ($values as $index => $value) {
        $positionedText[] = [[1, 0, 0, 1, $valuePositions[$index], 680 + ($index === 6 ? 0.7 : 0)], $value];
    }

    $result = app(ImportCertificatesFromPdf::class)
        ->extractPositionedRecords($positionedText, 'DG-NIV-RG8-0005-PC');

    expect($result['invalidCount'])->toBe(0)
        ->and($result['records'])->toHaveCount(1)
        ->and($result['records'][0])->toBe([
            'no' => '27',
            'marca' => 'ACME',
            'modelo' => 'X 200 TURBO',
            'tipo' => 'VEHÍCULO ESPECIAL',
            'fabricacion' => '2025',
            'anio' => 2026,
            'niv' => '8YZC7MCC2TD033214',
            'codigo' => 'DG-NIV-RG8-0005-PC',
        ]);
})->with([
    'original layout' => [
        [35, 76, 173, 284, 340, 403, 495],
        [40, 76, 164, 263, 353, 408, 459],
    ],
    'shifted and scaled layout' => [
        [48, 94, 192, 298, 359, 414, 500],
        [47, 97, 185, 278, 376, 422, 466],
    ],
]);

test('positioned pdf parsing explains why a detected row is invalid', function () {
    $positionedText = [];
    $headers = ['#', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Modelo', 'NIV'];
    $headerPositions = [35, 76, 173, 284, 340, 403, 495];
    $values = ['28', 'ACME', 'X 300', '', '25', '2026', '8YZC7MCC2TD033215'];
    $valuePositions = [40, 76, 164, 263, 353, 408, 459];

    foreach ($headers as $index => $header) {
        $positionedText[] = [[1, 0, 0, 1, $headerPositions[$index], 700], $header];
    }

    foreach ($values as $index => $value) {
        if ($value !== '') {
            $positionedText[] = [[1, 0, 0, 1, $valuePositions[$index], 680], $value];
        }
    }

    $result = app(ImportCertificatesFromPdf::class)
        ->extractPositionedRecords($positionedText, 'DG-NIV-RG8-0005-PC', 12);

    expect($result['records'])->toBeEmpty()
        ->and($result['invalidCount'])->toBe(1)
        ->and($result['invalidRows'])->toHaveCount(1)
        ->and($result['invalidRows'][0]['page'])->toBe(12)
        ->and($result['invalidRows'][0]['no'])->toBe('28')
        ->and($result['invalidRows'][0]['niv'])->toBe('8YZC7MCC2TD033215')
        ->and($result['invalidRows'][0]['reason'])
        ->toContain('Falta el campo Tipo.')
        ->toContain('Fabricación debe tener cuatro dígitos.');
});

test('validated continuous values split into pdf fragments are joined without spaces', function () {
    $positionedText = [];
    $headers = ['#', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Modelo', 'NIV'];
    $headerPositions = [35, 76, 173, 284, 340, 403, 495];

    foreach ($headers as $index => $header) {
        $positionedText[] = [[1, 0, 0, 1, $headerPositions[$index], 700], $header];
    }

    $positionedText = [
        ...$positionedText,
        [[1, 0, 0, 1, 40, 680], '2'],
        [[1, 0, 0, 1, 44, 680], '001'],
        [[1, 0, 0, 1, 76, 680], 'BERA'],
        [[1, 0, 0, 1, 164, 680], 'BR 150 BRF'],
        [[1, 0, 0, 1, 263, 680], 'MOTOCICLETA'],
        [[1, 0, 0, 1, 353, 680], '2026'],
        [[1, 0, 0, 1, 408, 680], '2026'],
        [[1, 0, 0, 1, 459, 680], '8YZC7MCC2TD031852'],
    ];

    $result = app(ImportCertificatesFromPdf::class)
        ->extractPositionedRecords($positionedText, 'DG-NIV-RG8-0005-PC', 35);

    expect($result['invalidCount'])->toBe(0)
        ->and($result['invalidRows'])->toBeEmpty()
        ->and($result['records'])->toHaveCount(1)
        ->and($result['records'][0]['no'])->toBe('2001')
        ->and($result['records'][0]['marca'])->toBe('BERA')
        ->and($result['records'][0]['modelo'])->toBe('BR 150 BRF')
        ->and($result['records'][0]['tipo'])->toBe('MOTOCICLETA');

    $positionedText[7] = [[1, 0, 0, 1, 40, 680], '2'];
    $positionedText[8] = [[1, 0, 0, 1, 47, 680], '001'];

    $resultWithVisibleSpace = app(ImportCertificatesFromPdf::class)
        ->extractPositionedRecords(array_values($positionedText), 'DG-NIV-RG8-0005-PC', 35);

    expect($resultWithVisibleSpace['records'])->toBeEmpty()
        ->and($resultWithVisibleSpace['invalidCount'])->toBe(1)
        ->and($resultWithVisibleSpace['invalidRows'][0]['no'])->toBe('2 001')
        ->and($resultWithVisibleSpace['invalidRows'][0]['reason'])
        ->toContain('El campo NO debe contener únicamente números.');
});

test('each pdf analysis category can be exported independently', function () {
    $analysis = [
        'controlNumber' => 'DG-NIV-RG8-0005-PC',
        'records' => [[
            'no' => '1',
            'marca' => 'BERA',
            'modelo' => 'BR 150',
            'tipo' => 'MOTOCICLETA',
            'fabricacion' => '2026',
            'anio' => 2026,
            'niv' => '8YZC7MCC0TD033213',
            'codigo' => 'DG-NIV-RG8-0005-PC',
        ]],
        'duplicateRows' => [[
            'no' => '2',
            'niv' => '8YZC7MCC2TD033214',
            'values' => ['2', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', '2026', '8YZC7MCC2TD033214'],
            'reason' => 'El NIV ya existe en la base de datos.',
        ]],
        'invalidRows' => [[
            'page' => 3,
            'no' => '3',
            'niv' => 'NIV-CORTO',
            'values' => ['3', 'BERA', 'BR 150', 'MOTOCICLETA', '2026', '2026', 'NIV-CORTO'],
            'reason' => 'El NIV debe tener 17 caracteres válidos.',
        ]],
    ];
    $expectedStatuses = [
        'ready' => 'Listo para importar',
        'duplicates' => 'Duplicado',
        'invalid' => 'Inválido',
    ];

    foreach ($expectedStatuses as $category => $expectedStatus) {
        $response = app(ExportPdfAnalysis::class)->handle($category, $analysis);
        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        $reader->close();

        expect($rows)->toHaveCount(2)
            ->and($rows[1][0])->toBe($expectedStatus)
            ->and($response->headers->get('content-disposition'))->toContain($category);
    }
});

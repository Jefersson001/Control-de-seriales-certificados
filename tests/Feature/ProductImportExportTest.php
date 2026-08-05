<?php

use App\Actions\Products\ImportProducts;
use App\Models\Product;
use App\Models\User;
use App\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

test('products can be imported from the expected excel columns', function () {
    Product::factory()->create(['name' => 'Descripción existente']);
    $filePath = createProductsWorkbook([
        ['Moto urbana', 'Primero A', 'Segundo B', 'NIV-001', 2026],
        ['Descripción existente', 'Primero C', 'Segundo D', 'NIV-002', 2025],
        ['', '', '', '', 'año inválido'],
    ]);

    $result = app(ImportProducts::class)->handle($filePath);

    expect($result)->toBe(['imported' => 1, 'skipped' => 2]);
    $this->assertDatabaseHas((new Product)->getTable(), [
        'name' => 'Moto urbana',
        'first_value' => 'Primero A',
        'second_value' => 'Segundo B',
        'niv' => 'NIV-001',
        'year' => 2026,
    ]);

    @unlink($filePath);
});

test('product import rejects unexpected excel headers', function () {
    $filePath = createProductsWorkbook([], ['Producto']);

    expect(fn () => app(ImportProducts::class)->handle($filePath))
        ->toThrow(RuntimeException::class, 'Las columnas deben ser');

    @unlink($filePath);
});

test('authorized users can export the filtered products to xlsx', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewProducts->value],
    ]);
    Product::factory()->create(['name' => 'Moto encontrada', 'niv' => 'NIV-BUSCADO', 'year' => 2026]);
    Product::factory()->create(['name' => 'Producto oculto', 'niv' => 'NIV-OCULTO']);

    $response = $this->actingAs($user)->get(route('products.export', ['search' => 'NIV-BUSCADO']));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

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

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['Descripción', '1ero', '2do', 'NIV', 'Año'])
        ->and($rows[1][0])->toBe('Moto encontrada')
        ->and($rows[1][3])->toBe('NIV-BUSCADO');
});

test('guests cannot export products', function () {
    $this->get(route('products.export'))->assertRedirect(route('login'));
});

/** @param list<list<mixed>> $rows
 *  @param list<mixed> $headers
 */
function createProductsWorkbook(
    array $rows,
    array $headers = ['Descripción', '1ero', '2do', 'NIV', 'Año'],
): string {
    $filePath = tempnam(sys_get_temp_dir(), 'product-import-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($filePath);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $filePath;
}

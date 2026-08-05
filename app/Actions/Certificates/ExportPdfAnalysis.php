<?php

namespace App\Actions\Certificates;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportPdfAnalysis
{
    private const array HEADERS = [
        'Estado',
        'Página',
        'NO',
        'Marca',
        'Modelo',
        'Tipo',
        'Fabricación',
        'Año',
        'NIV',
        'Código',
        'Motivo',
    ];

    /**
     * @param  array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     duplicateRows: list<array{no: string, niv: string, values: list<string>, reason: string}>,
     *     invalidRows: list<array{page: int|null, no: string, niv: string, values: list<string>, reason: string}>
     * }  $analysis
     */
    public function handle(string $category, array $analysis): BinaryFileResponse
    {
        $rows = $this->rowsFor($category, $analysis);

        if ($rows === []) {
            throw new RuntimeException('No hay registros disponibles para esta exportación.');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'analisis-pdf-');

        if ($temporaryFile === false) {
            throw new RuntimeException('No se pudo preparar el archivo de exportación.');
        }

        $xlsxPath = $temporaryFile.'.xlsx';
        rename($temporaryFile, $xlsxPath);

        $writer = new Writer;
        $writer->openToFile($xlsxPath);
        $writer->addRow(Row::fromValues(self::HEADERS));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()
            ->download(
                $xlsxPath,
                "analisis-pdf-{$category}-".now()->format('Y-m-d-His').'.xlsx',
            )
            ->deleteFileAfterSend();
    }

    /**
     * @param  array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     duplicateRows: list<array{no: string, niv: string, values: list<string>, reason: string}>,
     *     invalidRows: list<array{page: int|null, no: string, niv: string, values: list<string>, reason: string}>
     * }  $analysis
     * @return list<list<int|string|null>>
     */
    private function rowsFor(string $category, array $analysis): array
    {
        return match ($category) {
            'ready' => array_map(
                fn (array $record): array => [
                    'Listo para importar',
                    null,
                    $record['no'],
                    $record['marca'],
                    $record['modelo'],
                    $record['tipo'],
                    $record['fabricacion'],
                    $record['anio'],
                    $record['niv'],
                    $record['codigo'],
                    '',
                ],
                $analysis['records'],
            ),
            'duplicates' => array_map(
                fn (array $row): array => [
                    'Duplicado',
                    null,
                    ...$row['values'],
                    $analysis['controlNumber'],
                    $row['reason'],
                ],
                $analysis['duplicateRows'],
            ),
            'invalid' => array_map(
                fn (array $row): array => [
                    'Inválido',
                    $row['page'],
                    ...$row['values'],
                    $analysis['controlNumber'],
                    $row['reason'],
                ],
                $analysis['invalidRows'],
            ),
            default => throw new RuntimeException('La categoría de exportación no es válida.'),
        };
    }
}

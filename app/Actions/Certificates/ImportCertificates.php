<?php

namespace App\Actions\Certificates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class ImportCertificates
{
    private const array EXPECTED_HEADERS = [
        'no',
        'marca',
        'modelo',
        'tipo',
        'fabricacion',
        'ano',
        'niv',
        'codigo',
    ];

    /**
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     skippedRows: list<array{row: int, reason: string, values: list<string>}>
     * }
     */
    public function handle(string $filePath): array
    {
        set_time_limit(0);

        $reader = new Reader;
        $reader->open($filePath);

        try {
            return DB::transaction(function () use ($reader): array {
                $imported = 0;
                $skipped = 0;
                $skippedRows = [];
                $batch = [];
                $headerWasRead = false;
                $rowNumber = 0;

                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $rowNumber++;
                        $values = $row->toArray();

                        if (! $headerWasRead) {
                            $this->ensureHeadersAreValid($values);
                            $headerWasRead = true;

                            continue;
                        }

                        $mappedRow = $this->mapRow($values);

                        if ($mappedRow['record'] === null) {
                            $skipped++;

                            if (count($skippedRows) < 100) {
                                $skippedRows[] = [
                                    'row' => $rowNumber,
                                    'reason' => implode(' ', $mappedRow['reasons']),
                                    'values' => array_map(
                                        fn (mixed $value): string => Str::of((string) $value)->trim()->value(),
                                        array_pad(array_slice($values, 0, 8), 8, null),
                                    ),
                                ];
                            }

                            continue;
                        }

                        $batch[] = $mappedRow['record'];

                        if (count($batch) === 500) {
                            DB::table('ms_certificados')->insert($batch);
                            $imported += count($batch);
                            $batch = [];
                        }
                    }

                    break;
                }

                if (! $headerWasRead) {
                    throw new RuntimeException('El archivo de Excel está vacío.');
                }

                if ($batch !== []) {
                    DB::table('ms_certificados')->insert($batch);
                    $imported += count($batch);
                }

                return [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'skippedRows' => $skippedRows,
                ];
            });
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  array<int, mixed>  $headers
     */
    private function ensureHeadersAreValid(array $headers): void
    {
        $normalizedHeaders = array_map(
            fn (mixed $header): string => Str::of((string) $header)->ascii()->trim()->lower()->value(),
            array_slice($headers, 0, 8),
        );

        if ($normalizedHeaders !== self::EXPECTED_HEADERS) {
            throw new RuntimeException(
                'Las columnas deben ser: NO, Marca, Modelo, Tipo, Fabricación, Año, NIV y Código.',
            );
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{
     *     record: array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string, created_at: CarbonImmutable, updated_at: CarbonImmutable}|null,
     *     reasons: list<string>
     * }
     */
    private function mapRow(array $values): array
    {
        $values = array_pad(array_slice($values, 0, 8), 8, null);
        $stringValues = array_map(
            fn (mixed $value): string => Str::of((string) $value)->trim()->value(),
            $values,
        );

        [$no, $marca, $modelo, $tipo, $fabricacion, $anio, $niv, $codigo] = $stringValues;
        $reasons = [];
        $requiredFields = [
            'NO' => $no,
            'Marca' => $marca,
            'Modelo' => $modelo,
            'Tipo' => $tipo,
            'Fabricación' => $fabricacion,
            'NIV' => $niv,
            'Código' => $codigo,
        ];

        foreach ($requiredFields as $field => $value) {
            if ($value === '') {
                $reasons[] = "Falta el campo {$field}.";
            }
        }

        if ($anio === '') {
            $reasons[] = 'Falta el campo Año.';
        } elseif (! ctype_digit($anio)) {
            $reasons[] = 'El campo Año debe contener únicamente números.';
        } elseif ((int) $anio > 65535) {
            $reasons[] = 'El valor del campo Año es demasiado grande.';
        }

        $maximumLengths = [
            'NO' => [$no, 50],
            'Marca' => [$marca, 100],
            'Modelo' => [$modelo, 100],
            'Tipo' => [$tipo, 100],
            'Fabricación' => [$fabricacion, 100],
            'NIV' => [$niv, 50],
            'Código' => [$codigo, 100],
        ];

        foreach ($maximumLengths as $field => [$value, $maximumLength]) {
            if (Str::length($value) > $maximumLength) {
                $reasons[] = "El campo {$field} supera {$maximumLength} caracteres.";
            }
        }

        if ($reasons !== []) {
            return ['record' => null, 'reasons' => $reasons];
        }

        return [
            'record' => [
                'no' => $no,
                'marca' => $marca,
                'modelo' => $modelo,
                'tipo' => $tipo,
                'fabricacion' => $fabricacion,
                'anio' => (int) $anio,
                'niv' => $niv,
                'codigo' => $codigo,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'reasons' => [],
        ];
    }
}

<?php

namespace App\Actions\Products;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class ImportProducts
{
    private const array EXPECTED_HEADERS = ['descripcion', '1ero', '2do', 'niv', 'ano'];

    /** @return array{imported: int, skipped: int} */
    public function handle(string $filePath): array
    {
        set_time_limit(0);

        $reader = new Reader;
        $reader->open($filePath);

        try {
            return DB::transaction(function () use ($reader): array {
                $existingDescriptions = Product::query()
                    ->pluck('name')
                    ->mapWithKeys(fn (string $description): array => [Str::lower($description) => true])
                    ->all();
                $imported = 0;
                $skipped = 0;
                $batch = [];
                $headerWasRead = false;

                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $values = $row->toArray();

                        if (! $headerWasRead) {
                            $this->ensureHeadersAreValid($values);
                            $headerWasRead = true;

                            continue;
                        }

                        $record = $this->mapRow($values);
                        $descriptionKey = Str::lower($record['name'] ?? '');

                        if ($record === null || isset($existingDescriptions[$descriptionKey])) {
                            $skipped++;

                            continue;
                        }

                        $existingDescriptions[$descriptionKey] = true;
                        $batch[] = $record;

                        if (count($batch) === 500) {
                            Product::query()->insert($batch);
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
                    Product::query()->insert($batch);
                    $imported += count($batch);
                }

                return ['imported' => $imported, 'skipped' => $skipped];
            });
        } finally {
            $reader->close();
        }
    }

    /** @param array<int, mixed> $headers */
    private function ensureHeadersAreValid(array $headers): void
    {
        $normalizedHeaders = array_map(
            fn (mixed $header): string => Str::of((string) $header)->ascii()->trim()->lower()->value(),
            array_slice($headers, 0, 5),
        );

        if ($normalizedHeaders !== self::EXPECTED_HEADERS) {
            throw new RuntimeException('Las columnas deben ser: Descripción, 1ero, 2do, NIV y Año.');
        }
    }

    /** @param array<int, mixed> $values
     *  @return array{name: string, first_value: string|null, second_value: string|null, niv: string|null, year: int|null, created_at: CarbonImmutable, updated_at: CarbonImmutable}|null
     */
    private function mapRow(array $values): ?array
    {
        $values = array_map(
            fn (mixed $value): string => Str::of((string) $value)->trim()->value(),
            array_pad(array_slice($values, 0, 5), 5, null),
        );
        [$description, $firstValue, $secondValue, $niv, $year] = $values;

        if ($description === '' || Str::length($description) > 255) {
            return null;
        }

        if ($year !== '' && (! ctype_digit($year) || (int) $year > 65535)) {
            return null;
        }

        if (Str::length($firstValue) > 255 || Str::length($secondValue) > 255 || Str::length($niv) > 50) {
            return null;
        }

        return [
            'name' => $description,
            'first_value' => $firstValue !== '' ? $firstValue : null,
            'second_value' => $secondValue !== '' ? $secondValue : null,
            'niv' => $niv !== '' ? $niv : null,
            'year' => $year !== '' ? (int) $year : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

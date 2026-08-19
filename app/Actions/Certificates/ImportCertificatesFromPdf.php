<?php

namespace App\Actions\Certificates;

use App\Models\MsCertificado;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

class ImportCertificatesFromPdf
{
    /**
     * @return array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     duplicateCount: int,
     *     duplicateRows: list<array{no: string, niv: string, values: list<string>, reason: string}>,
     *     invalidCount: int,
     *     invalidRows: list<array{page: int|null, no: string, niv: string, values: list<string>, reason: string}>
     * }
     */
    public function parse(string $filePath): array
    {
        $result = $this->parseForComparison($filePath);

        return $this->prepareResult(
            $result['controlNumber'],
            $result['records'],
            $result['invalidCount'],
            $result['invalidRows'],
        );
    }

    /**
     * Extract every valid occurrence without consulting the certificate database.
     *
     * @return array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     invalidCount: int,
     *     invalidRows: list<array{page: int|null, no: string, niv: string, values: list<string>, reason: string}>
     * }
     */
    public function parseForComparison(string $filePath): array
    {
        set_time_limit(0);

        $config = new Config;
        $config->setDataTmFontInfoHasToBeIncluded(true);
        $pdf = (new Parser([], $config))->parseFile($filePath);
        $this->normalizeDocumentDetails($pdf);
        $controlNumber = null;
        $records = [];
        $invalidCount = 0;
        $invalidRows = [];

        foreach ($pdf->getPages() as $pageIndex => $page) {
            $positionedText = $page->getDataTm();
            $pageText = implode(' ', array_column($positionedText, 1));
            $controlNumber ??= $this->findControlNumber($pageText);

            if ($controlNumber === null) {
                continue;
            }

            $pageResult = $this->extractPositionedRecords($positionedText, $controlNumber, $pageIndex + 1);
            $records = [...$records, ...$pageResult['records']];
            $invalidCount += $pageResult['invalidCount'];
            $invalidRows = [...$invalidRows, ...$pageResult['invalidRows']];
        }

        if ($controlNumber === null) {
            throw new RuntimeException('No se encontró el Número de Control en el PDF.');
        }

        if ($records === [] && $invalidRows === []) {
            throw new RuntimeException('No se encontraron registros válidos en la tabla del PDF.');
        }

        return [
            'controlNumber' => $controlNumber,
            'records' => $records,
            'invalidCount' => $invalidCount,
            'invalidRows' => $invalidRows,
        ];
    }

    /**
     * Fallback parser for PDFs that provide a correctly delimited text layer.
     *
     * @return array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     duplicateCount: int,
     *     duplicateRows: list<array{no: string, niv: string, values: list<string>, reason: string}>,
     *     invalidCount: int
     * }
     */
    public function parseText(string $text): array
    {
        $controlNumber = $this->extractControlNumber($text);
        $records = [];
        $invalidCount = 0;
        $invalidRows = [];
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*\d+\s{2,}/u', $line) !== 1) {
                continue;
            }

            $record = $this->parseRow($line, $controlNumber);

            if ($record === null) {
                $invalidCount++;
                $invalidRows[] = [
                    'page' => null,
                    'no' => '',
                    'niv' => '',
                    'values' => [trim($line)],
                    'reason' => 'La distribución del texto de esta fila no pudo reconocerse.',
                ];

                continue;
            }

            $records[] = $record;
        }

        return $this->prepareResult($controlNumber, $records, $invalidCount, $invalidRows);
    }

    /**
     * Extract table rows using the positions of the column headers on the page.
     *
     * @param  array<int, array{0: array<int, float|int|string>, 1: string}>  $positionedText
     * @return array{
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     invalidCount: int,
     *     invalidRows: list<array{page: int|null, no: string, niv: string, values: list<string>, reason: string}>
     * }
     */
    public function extractPositionedRecords(
        array $positionedText,
        string $controlNumber,
        ?int $pageNumber = null,
    ): array {
        $items = collect($positionedText)
            ->map(function (array $item): ?array {
                $matrix = $item[0] ?? [];
                $text = trim($item[1] ?? '');

                if ($text === '' || ! isset($matrix[4], $matrix[5])) {
                    return null;
                }

                return [
                    'x' => (float) $matrix[4],
                    'y' => (float) $matrix[5],
                    'text' => preg_replace('/\s+/u', ' ', $text) ?? $text,
                    'fontSize' => (float) ($item[3] ?? 8),
                ];
            })
            ->filter()
            ->values();

        $header = $this->findHeader($items->all());

        if ($header === null) {
            return ['records' => [], 'invalidCount' => 0, 'invalidRows' => []];
        }

        $boundaries = [];

        for ($index = 0; $index < count($header['columns']) - 1; $index++) {
            $boundaries[] = ($header['columns'][$index] + $header['columns'][$index + 1]) / 2;
        }

        $records = [];
        $invalidCount = 0;
        $invalidRows = [];
        $rowPositions = [];

        foreach ($items as $item) {
            if (
                $item['y'] >= $header['y'] - 3
                || $item['x'] >= $boundaries[0]
                || preg_match('/^\d(?:[\d\s]*\d)?$/', $item['text']) !== 1
            ) {
                continue;
            }

            $positionAlreadyRegistered = false;

            foreach ($rowPositions as $rowPosition) {
                if (abs($rowPosition - $item['y']) <= 4) {
                    $positionAlreadyRegistered = true;

                    break;
                }
            }

            if (! $positionAlreadyRegistered) {
                $rowPositions[] = $item['y'];
            }
        }

        foreach ($rowPositions as $rowPosition) {
            $rowItems = $items
                ->filter(fn (array $item): bool => abs($item['y'] - $rowPosition) <= 4)
                ->sortBy('x')
                ->values()
                ->all();
            $cells = array_fill(0, 7, []);

            foreach ($rowItems as $item) {
                $column = 0;

                while (isset($boundaries[$column]) && $item['x'] >= $boundaries[$column]) {
                    $column++;
                }

                $cells[$column][] = $item;
            }

            $values = array_map(
                fn (array $parts, int $column): string => $this->joinCellParts($parts, $column),
                $cells,
                array_keys($cells),
            );

            if (count(array_filter($values, fn (string $value): bool => $value !== '')) < 5) {
                continue;
            }

            $record = $this->recordFromCells($values, $controlNumber);

            if ($record === null) {
                $repairedValues = $this->repairTrailingCells($rowItems, $boundaries, $values);

                if ($repairedValues !== null) {
                    $repairedRecord = $this->recordFromCells($repairedValues, $controlNumber);

                    if ($repairedRecord !== null) {
                        $values = $repairedValues;
                        $records[] = $repairedRecord;

                        continue;
                    }
                }

                $invalidCount++;
                $invalidRows[] = [
                    'page' => $pageNumber,
                    'no' => $values[0] ?? '',
                    'niv' => $values[6] ?? '',
                    'values' => $values,
                    'reason' => implode(' ', $this->invalidReasons($values)),
                ];

                continue;
            }

            $records[] = $record;
        }

        return compact('records', 'invalidCount', 'invalidRows');
    }

    /**
     * @param  list<array{x: float, y: float, text: string, fontSize: float}>  $parts
     */
    private function joinCellParts(array $parts, int $column): string
    {
        if ($parts === []) {
            return '';
        }

        if (! in_array($column, [0, 4, 5, 6], true)) {
            return trim(implode(' ', array_column($parts, 'text')));
        }

        $value = $parts[0]['text'];

        for ($index = 1; $index < count($parts); $index++) {
            $previous = $parts[$index - 1];
            $current = $parts[$index];
            $separator = '';

            if ($column !== 6) {
                $estimatedPreviousWidth = Str::length($previous['text'])
                    * max($previous['fontSize'], 1)
                    * 0.7;

                if (($current['x'] - $previous['x']) > $estimatedPreviousWidth) {
                    $separator = ' ';
                }
            }

            $value .= $separator.$current['text'];
        }

        return trim($value);
    }

    /**
     * Reassemble the Tipo/Fabricación/Año/NIV columns when a row is invalid
     * because a text fragment crossed a column boundary.
     *
     * @param  list<array{x: float, y: float, text: string, fontSize: float}>  $rowItems
     * @param  list<float>  $boundaries
     * @param  array<int, string>  $values
     * @return array<int, string>|null
     */
    private function repairTrailingCells(array $rowItems, array $boundaries, array $values): ?array
    {
        $boundaryTipo = $boundaries[3] ?? null;
        $boundaryModelo = $boundaries[2] ?? null;

        if ($boundaryTipo === null || $boundaryModelo === null) {
            return null;
        }

        $tipoParts = [];
        $tailParts = [];

        foreach ($rowItems as $item) {
            if ($item['x'] >= $boundaryTipo) {
                $tailParts[] = $item;
            } elseif ($item['x'] >= $boundaryModelo) {
                $tipoParts[] = $item;
            }
        }

        if ($tailParts === []) {
            return null;
        }

        $joined = implode('', array_column($tailParts, 'text'));

        if (preg_match('/([A-HJ-NPR-Z0-9]+)$/', $joined, $match) !== 1 || mb_strlen($match[1]) < 17) {
            return null;
        }

        $niv = mb_substr($match[1], -17);
        $joined = mb_substr($joined, 0, -17);

        $anio = $this->popRightmostDigits($joined);
        $fabricacion = $this->popRightmostDigits($joined);

        if ($anio === null || $fabricacion === null) {
            return null;
        }

        $tipo = trim(implode(' ', array_column($tipoParts, 'text')).$joined);

        return [
            $values[0] ?? '',
            $values[1] ?? '',
            $values[2] ?? '',
            $tipo,
            $fabricacion,
            $anio,
            $niv,
        ];
    }

    private function popRightmostDigits(string &$text): ?string
    {
        if (preg_match_all('/\d{4}/', $text, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
            return null;
        }

        $last = end($matches[0]);
        $text = substr($text, 0, $last[1]).substr($text, $last[1] + 4);

        return $last[0];
    }

    /**
     * @param  list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>  $records
     * @return array{imported: int, skipped: int}
     */
    public function store(array $records): array
    {
        return Cache::lock('certificate-pdf-import', 30)->block(5, function () use ($records): array {
            return DB::transaction(fn (): array => $this->storeReadyRecords($records));
        });
    }

    /**
     * @param  array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     duplicateRows: list<array{no: string, niv: string, values: list<string>, reason: string}>,
     *     invalidRows: list<array{page: int|null, no: string, niv: string, values: list<string>, reason: string}>
     * }  $analysis
     * @return array{imported: int, ready: int, duplicates: int, invalid: int, skipped: int}
     */
    public function storeSelection(
        array $analysis,
        bool $includeReady,
        bool $includeDuplicates,
        bool $includeInvalid,
    ): array {
        return Cache::lock('certificate-pdf-import', 30)->block(
            5,
            function () use ($analysis, $includeReady, $includeDuplicates, $includeInvalid): array {
                return DB::transaction(function () use (
                    $analysis,
                    $includeReady,
                    $includeDuplicates,
                    $includeInvalid,
                ): array {
                    $readyResult = $includeReady
                        ? $this->storeReadyRecords($analysis['records'])
                        : ['imported' => 0, 'skipped' => 0];
                    $duplicateRecords = $includeDuplicates
                        ? $this->recordsFromAnalysisRows(
                            $analysis['duplicateRows'],
                            $analysis['controlNumber'],
                        )
                        : [];
                    $invalidRecords = $includeInvalid
                        ? $this->recordsFromAnalysisRows(
                            $analysis['invalidRows'],
                            $analysis['controlNumber'],
                        )
                        : [];
                    $duplicates = $this->insertRecords($duplicateRecords);
                    $invalid = $this->insertRecords($invalidRecords);

                    return [
                        'imported' => $readyResult['imported'] + $duplicates + $invalid,
                        'ready' => $readyResult['imported'],
                        'duplicates' => $duplicates,
                        'invalid' => $invalid,
                        'skipped' => $readyResult['skipped'],
                    ];
                });
            },
        );
    }

    /**
     * @param  list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>  $records
     * @return array{imported: int, skipped: int}
     */
    private function storeReadyRecords(array $records): array
    {
        $imported = 0;
        $skipped = 0;

        foreach (array_chunk($records, 500) as $recordChunk) {
            $existingNivs = MsCertificado::query()
                ->whereIn('niv', array_column($recordChunk, 'niv'))
                ->pluck('niv')
                ->flip();
            $newRecords = array_values(array_filter(
                $recordChunk,
                fn (array $record): bool => ! $existingNivs->has($record['niv']),
            ));

            $skipped += count($recordChunk) - count($newRecords);
            $imported += $this->insertRecords($newRecords);
        }

        return compact('imported', 'skipped');
    }

    /**
     * @param  list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>  $records
     */
    private function insertRecords(array $records): int
    {
        $imported = 0;

        foreach (array_chunk($records, 500) as $recordChunk) {
            if ($recordChunk === []) {
                continue;
            }

            $timestamp = now();
            $recordsWithTimestamps = array_map(
                fn (array $record): array => [
                    ...$record,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $recordChunk,
            );

            MsCertificado::query()->insert($recordsWithTimestamps);
            $imported += count($recordsWithTimestamps);
        }

        return $imported;
    }

    /**
     * @param  list<array{values: list<string>}>  $rows
     * @return list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>
     */
    private function recordsFromAnalysisRows(array $rows, string $controlNumber): array
    {
        return array_map(function (array $row) use ($controlNumber): array {
            $values = array_pad($row['values'], 7, '');
            $year = ctype_digit($values[5]) && (int) $values[5] <= 65535
                ? (int) $values[5]
                : 0;

            return [
                'no' => Str::substr($values[0], 0, 50),
                'marca' => Str::substr($values[1], 0, 100),
                'modelo' => Str::substr($values[2], 0, 100),
                'tipo' => Str::substr($values[3], 0, 100),
                'fabricacion' => Str::substr($values[4], 0, 100),
                'anio' => $year,
                'niv' => Str::substr($values[6], 0, 50),
                'codigo' => Str::substr($controlNumber, 0, 100),
            ];
        }, $rows);
    }

    private function extractControlNumber(string $text): string
    {
        $controlNumber = $this->findControlNumber($text);

        if ($controlNumber === null) {
            throw new RuntimeException('No se encontró el Número de Control en el PDF.');
        }

        return $controlNumber;
    }

    private function findControlNumber(string $text): ?string
    {
        if (
            preg_match(
                '/NÚMERO\s+DE\s+CONTROL\.?\s*:?\s*([A-Z0-9\s-]+?)(?=\s+(?:FABRICADO|EL\s+SERVICIO|CONSTANCIA|Nro|FECHA|\n|$))/iu',
                $text,
                $matches,
            ) !== 1
        ) {
            if (
                preg_match(
                    '/NÚMERO\s+DE\s+CONTROL\.?\s*:?\s*([A-Z0-9]+(?:\s*-\s*[A-Z0-9]+)+)/iu',
                    $text,
                    $matches,
                ) !== 1
            ) {
                return null;
            }
        }

        $clean = Str::of($matches[1])
            ->replaceMatches('/\s+/u', '')
            ->upper()
            ->toString();

        return $clean !== '' ? $clean : null;
    }

    private function normalizeDocumentDetails(Document $document): void
    {
        try {
            $refProp = new \ReflectionProperty($document, 'details');
            $refProp->setAccessible(true);
            $details = $refProp->getValue($document);

            if (is_array($details) && isset($details['Producer']) && str_starts_with((string) $details['Producer'], 'FPDF')) {
                $details['Producer'] = 'Disabled_FPDF_Workaround';
                $refProp->setValue($document, $details);
            }
        } catch (\Throwable) {
            // Silently ignore if reflection is unavailable
        }
    }

    /**
     * @param  list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>  $records
     * @return array{
     *     controlNumber: string,
     *     records: list<array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}>,
     *     duplicateCount: int,
     *     duplicateRows: list<array{no: string, niv: string, values: list<string>, reason: string}>,
     *     invalidCount: int
     * }
     */
    private function prepareResult(
        string $controlNumber,
        array $records,
        int $invalidCount,
        array $invalidRows = [],
    ): array {
        $recordsByNiv = [];
        $duplicateCount = 0;
        $duplicateRows = [];

        foreach ($records as $record) {
            if (array_key_exists($record['niv'], $recordsByNiv)) {
                $duplicateCount++;
                $duplicateRows[] = $this->duplicateRow(
                    $record,
                    'El NIV está repetido dentro del mismo PDF.',
                );

                continue;
            }

            $recordsByNiv[$record['niv']] = $record;
        }

        if ($recordsByNiv === [] && $invalidRows === []) {
            throw new RuntimeException('No se encontraron registros válidos en la tabla del PDF.');
        }

        $existingNivs = collect();

        foreach (array_chunk(array_keys($recordsByNiv), 500) as $nivChunk) {
            $existingNivs->push(
                ...MsCertificado::query()->whereIn('niv', $nivChunk)->pluck('niv')->all(),
            );
        }

        foreach ($existingNivs->unique() as $existingNiv) {
            if (array_key_exists($existingNiv, $recordsByNiv)) {
                $duplicateRows[] = $this->duplicateRow(
                    $recordsByNiv[$existingNiv],
                    'El NIV ya existe en la base de datos.',
                );
                unset($recordsByNiv[$existingNiv]);
                $duplicateCount++;
            }
        }

        return [
            'controlNumber' => $controlNumber,
            'records' => array_values($recordsByNiv),
            'duplicateCount' => $duplicateCount,
            'duplicateRows' => $duplicateRows,
            'invalidCount' => $invalidCount,
            'invalidRows' => $invalidRows,
        ];
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $items
     * @return array{y: float, columns: list<float>}|null
     */
    private function findHeader(array $items): ?array
    {
        foreach ($items as $item) {
            if ($this->normalizeHeader($item['text']) !== '#') {
                continue;
            }

            $headerItems = collect($items)
                ->filter(fn (array $candidate): bool => abs($candidate['y'] - $item['y']) <= 4)
                ->sortBy('x')
                ->values();
            $expectedHeaders = ['#', 'marca', 'modelo', 'tipo', 'fabricacion', 'modelo', 'niv'];
            $columns = [];
            $searchFrom = 0;

            foreach ($expectedHeaders as $expectedHeader) {
                $found = null;

                for ($start = $searchFrom; $start < $headerItems->count(); $start++) {
                    $combinedHeader = '';

                    for ($index = $start; $index < $headerItems->count(); $index++) {
                        $candidate = $headerItems->get($index);
                        $combinedHeader .= $this->normalizeHeader($candidate['text']);

                        if ($combinedHeader === $expectedHeader) {
                            $found = $headerItems->get($start);
                            $searchFrom = $index + 1;

                            break 2;
                        }

                        if (! str_starts_with($expectedHeader, $combinedHeader)) {
                            break;
                        }
                    }
                }

                if ($found === null) {
                    $columns = [];

                    break;
                }

                $columns[] = $found['x'];
            }

            if (count($columns) === 7) {
                return ['y' => $item['y'], 'columns' => $columns];
            }
        }

        return null;
    }

    private function normalizeHeader(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9#]+/', '')
            ->toString();
    }

    /**
     * @return array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}|null
     */
    private function parseRow(string $line, string $controlNumber): ?array
    {
        if (
            preg_match(
                '/^\s*(\d+)\s{2,}([^\t]+)\t(.+?)\s+([\p{L}ÁÉÍÓÚÑ]+)\s+(\d{4})\s+(\d{4})\s+([A-HJ-NPR-Z0-9]{17})\s*$/u',
                $line,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return $this->recordFromCells([
            trim($matches[1]),
            trim($matches[2]),
            trim($matches[3]),
            trim($matches[4]),
            trim($matches[5]),
            trim($matches[6]),
            trim($matches[7]),
        ], $controlNumber);
    }

    /**
     * @param  array<int, string>  $cells
     * @return array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}|null
     */
    private function recordFromCells(array $cells, string $controlNumber): ?array
    {
        if ($this->invalidReasons($cells) !== []) {
            return null;
        }

        $record = [
            'no' => $cells[0],
            'marca' => $cells[1],
            'modelo' => $cells[2],
            'tipo' => $cells[3],
            'fabricacion' => $cells[4],
            'anio' => (int) $cells[5],
            'niv' => Str::upper($cells[6]),
            'codigo' => $controlNumber,
        ];

        foreach (['no', 'marca', 'modelo', 'tipo', 'fabricacion', 'niv', 'codigo'] as $field) {
            $maximumLength = in_array($field, ['no', 'niv'], true) ? 50 : 100;

            if (Str::length($record[$field]) > $maximumLength) {
                return null;
            }
        }

        return $record;
    }

    /**
     * @param  array{no: string, marca: string, modelo: string, tipo: string, fabricacion: string, anio: int, niv: string, codigo: string}  $record
     * @return array{no: string, niv: string, values: list<string>, reason: string}
     */
    private function duplicateRow(array $record, string $reason): array
    {
        return [
            'no' => $record['no'],
            'niv' => $record['niv'],
            'values' => [
                $record['no'],
                $record['marca'],
                $record['modelo'],
                $record['tipo'],
                $record['fabricacion'],
                (string) $record['anio'],
                $record['niv'],
            ],
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int, string>  $cells
     * @return list<string>
     */
    private function invalidReasons(array $cells): array
    {
        if (count($cells) !== 7) {
            return ['No se pudieron identificar las siete columnas de la fila.'];
        }

        $reasons = [];
        $fieldNames = ['NO', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV'];

        foreach ($cells as $index => $value) {
            if ($value === '') {
                $reasons[] = "Falta el campo {$fieldNames[$index]}.";
            }
        }

        if ($cells[0] !== '' && preg_match('/^\d+$/', $cells[0]) !== 1) {
            $reasons[] = 'El campo NO debe contener únicamente números.';
        }

        if ($cells[4] !== '' && preg_match('/^\d{4}$/', $cells[4]) !== 1) {
            $reasons[] = 'Fabricación debe tener cuatro dígitos.';
        }

        if ($cells[5] !== '' && preg_match('/^\d{4}$/', $cells[5]) !== 1) {
            $reasons[] = 'Año debe tener cuatro dígitos.';
        }

        if ($cells[6] !== '' && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $cells[6]) !== 1) {
            $reasons[] = 'El NIV debe tener 17 caracteres válidos.';
        }

        $maximumLengths = [50, 100, 100, 100, 100, null, 50];

        foreach ($cells as $index => $value) {
            if ($maximumLengths[$index] !== null && Str::length($value) > $maximumLengths[$index]) {
                $reasons[] = "El campo {$fieldNames[$index]} supera {$maximumLengths[$index]} caracteres.";
            }
        }

        return $reasons;
    }
}

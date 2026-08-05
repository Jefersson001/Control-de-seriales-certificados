<?php

namespace App\Actions\VehicleIdentificationRecords;

use Illuminate\Support\Str;

class CompareRequestSerialsWithPdf
{
    /**
     * @param  list<string>  $expectedSerials
     * @param  array{
     *     controlNumber: string,
     *     records: list<array{niv: string}>,
     *     invalidCount: int,
     *     invalidRows: list<array<string, mixed>>
     * }  $pdfAnalysis
     * @return array{
     *     controlNumber: string,
     *     expectedCount: int,
     *     pdfOccurrenceCount: int,
     *     matchedSerials: list<string>,
     *     duplicateSerials: list<array{serial: string, occurrences: int, requested: bool}>,
     *     unexpectedSerials: list<array{serial: string, occurrences: int}>,
     *     missingSerials: list<string>,
     *     invalidRows: list<array<string, mixed>>,
     *     exactMatch: bool
     * }
     */
    public function handle(array $expectedSerials, array $pdfAnalysis): array
    {
        $expected = collect($expectedSerials)
            ->map(fn (string $serial): string => Str::upper(trim($serial)))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $pdfOccurrences = collect($pdfAnalysis['records'])
            ->map(fn (array $record): string => Str::upper(trim((string) $record['niv'])))
            ->filter();
        $occurrencesBySerial = $pdfOccurrences->countBy();
        $matchedSerials = $expected
            ->filter(fn (string $serial): bool => $occurrencesBySerial->has($serial))
            ->values();
        $duplicateSerials = $occurrencesBySerial
            ->filter(fn (int $occurrences): bool => $occurrences > 1)
            ->map(fn (int $occurrences, string $serial): array => [
                'serial' => $serial,
                'occurrences' => $occurrences,
                'requested' => $expected->contains($serial),
            ])
            ->values();
        $unexpectedSerials = $occurrencesBySerial
            ->reject(fn (int $occurrences, string $serial): bool => $expected->contains($serial))
            ->map(fn (int $occurrences, string $serial): array => [
                'serial' => $serial,
                'occurrences' => $occurrences,
            ])
            ->values();
        $missingSerials = $expected
            ->reject(fn (string $serial): bool => $occurrencesBySerial->has($serial))
            ->values();
        $invalidRows = array_values($pdfAnalysis['invalidRows'] ?? []);

        return [
            'controlNumber' => $pdfAnalysis['controlNumber'],
            'expectedCount' => $expected->count(),
            'pdfOccurrenceCount' => $pdfOccurrences->count(),
            'matchedSerials' => $matchedSerials->all(),
            'duplicateSerials' => $duplicateSerials->all(),
            'unexpectedSerials' => $unexpectedSerials->all(),
            'missingSerials' => $missingSerials->all(),
            'invalidRows' => $invalidRows,
            'exactMatch' => $matchedSerials->count() === $expected->count()
                && $duplicateSerials->isEmpty()
                && $unexpectedSerials->isEmpty()
                && $missingSerials->isEmpty()
                && $invalidRows === [],
        ];
    }
}

<?php

namespace App\Actions\Dispatches;

use App\CertificateStatus;
use App\Models\MsCertificado;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;

class ImportDispatchNivs
{
    /** @return array{records: array<int, array<string, mixed>>, detected: int, unavailable: int} */
    public function handle(string $filePath): array
    {
        set_time_limit(0);
        $reader = new Reader;
        $reader->open($filePath);
        $values = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->toArray() as $cell) {
                        $value = Str::upper(trim((string) $cell));

                        if (preg_match('/^[A-Z0-9]{17}$/', $value) === 1) {
                            $values[$value] = true;
                        }
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $nivs = array_keys($values);
        $records = collect();

        foreach (array_chunk($nivs, 500) as $chunk) {
            $records->push(...MsCertificado::query()
                ->where('status', CertificateStatus::PendingDispatch)
                ->whereIn('niv', $chunk)
                ->orderBy('id')
                ->get(['id', 'niv', 'marca', 'modelo', 'codigo'])
                ->all());
        }

        return [
            'records' => $records->unique('id')->values()->map->toArray()->all(),
            'detected' => count($nivs),
            'unavailable' => max(0, count($nivs) - $records->pluck('niv')->unique()->count()),
        ];
    }
}

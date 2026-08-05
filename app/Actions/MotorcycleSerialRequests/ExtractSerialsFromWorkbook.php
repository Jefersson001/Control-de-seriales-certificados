<?php

namespace App\Actions\MotorcycleSerialRequests;

use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class ExtractSerialsFromWorkbook
{
    /** @return list<string> */
    public function handle(string $path): array
    {
        $reader = new Reader;
        $serials = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->toArray() as $value) {
                        if (! is_scalar($value)) {
                            continue;
                        }

                        preg_match_all('/(?<![A-Z0-9])[A-Z0-9]{17}(?![A-Z0-9])/i', (string) $value, $matches);

                        foreach ($matches[0] as $serial) {
                            $serials[strtoupper($serial)] = true;
                        }
                    }
                }
            }
        } finally {
            $reader->close();
        }

        if ($serials === []) {
            throw new RuntimeException('No se encontraron seriales de chasis válidos de 17 caracteres alfanuméricos.');
        }

        return array_keys($serials);
    }
}

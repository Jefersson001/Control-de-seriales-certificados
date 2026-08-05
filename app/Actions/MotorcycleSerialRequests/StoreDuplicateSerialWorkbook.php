<?php

namespace App\Actions\MotorcycleSerialRequests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class StoreDuplicateSerialWorkbook
{
    /**
     * @param  list<string>  $serials
     * @return array{name: string, path: string}
     */
    public function handle(array $serials, ?int $requestId): array
    {
        $directory = 'temporary-motorcycle-serial-conflicts';
        $path = $directory.'/'.Str::uuid().'.xlsx';
        Storage::disk('local')->makeDirectory($directory);

        $writer = new Writer;
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(Row::fromValues(['SERIAL REPETIDO']));

        foreach ($serials as $serial) {
            $writer->addRow(Row::fromValues([$serial]));
        }

        $writer->close();

        return [
            'name' => 'Seriales repetidos - '.($requestId === null ? 'Nueva solicitud' : "Solicitud {$requestId}").'.xlsx',
            'path' => $path,
        ];
    }
}

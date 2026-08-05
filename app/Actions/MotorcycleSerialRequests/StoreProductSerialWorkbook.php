<?php

namespace App\Actions\MotorcycleSerialRequests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class StoreProductSerialWorkbook
{
    /**
     * @param  list<string>  $serials
     * @return array{name: string, path: string}
     */
    public function handle(string $productName, string $niv, array $serials): array
    {
        $directory = 'motorcycle-serial-request-workbooks';
        $path = $directory.'/'.Str::uuid().'.xlsx';
        Storage::disk('local')->makeDirectory($directory);

        $writer = new Writer;
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(Row::fromValues(['SERIAL DE CHASIS']));

        foreach ($serials as $serial) {
            $writer->addRow(Row::fromValues([$serial]));
        }

        $writer->close();

        return [
            'name' => $this->fileName(null, $productName, $niv),
            'path' => $path,
        ];
    }

    public function fileName(?int $requestNumber, string $productName, string $niv): string
    {
        $safeProductName = trim((string) preg_replace('~[\\\\/:*?"<>|]+~', '-', $productName), " .-");
        $safeProductName = $safeProductName !== '' ? $safeProductName : 'Producto';
        $prefix = $requestNumber === null ? 'Solicitud pendiente' : "Solicitud {$requestNumber}";

        return "{$prefix} - {$safeProductName} - {$niv}.xlsx";
    }
}

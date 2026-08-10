<?php

namespace App\Actions\MotorcycleSerialRequests;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportProductLineSerials
{
    /**
     * @param  list<array{id: int|null, line_id: int|null, serial: string, created_at: string|null, updated_at: string|null}>  $serials
     */
    public function handle(array $serials, ?int $requestId, string $productName): BinaryFileResponse
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'product-line-serials-');
        abort_if($temporaryFile === false, 500, 'No fue posible preparar el archivo de exportación.');
        $xlsxPath = $temporaryFile.'.xlsx';
        rename($temporaryFile, $xlsxPath);

        $writer = new Writer;
        $writer->openToFile($xlsxPath);
        $writer->addRow(Row::fromValues(['ID', 'ID LÍNEA', 'SERIAL', 'CREADO', 'ACTUALIZADO']));

        foreach ($serials as $serial) {
            $writer->addRow(Row::fromValues([
                $serial['id'],
                $serial['line_id'],
                $serial['serial'],
                $serial['created_at'],
                $serial['updated_at'],
            ]));
        }

        $writer->close();

        $safeProductName = trim((string) preg_replace('~[\\/:*?"<>|]+~', '-', $productName), ' .-');
        $safeProductName = $safeProductName !== '' ? $safeProductName : 'Producto';
        $requestName = $requestId === null ? 'Nueva solicitud' : "Solicitud {$requestId}";

        return response()
            ->download($xlsxPath, "Seriales - {$requestName} - {$safeProductName}.xlsx")
            ->deleteFileAfterSend();
    }
}

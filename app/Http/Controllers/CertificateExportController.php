<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportCertificatesRequest;
use App\Models\MsCertificado;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CertificateExportController extends Controller
{
    public function __invoke(ExportCertificatesRequest $request): BinaryFileResponse
    {
        $search = (string) ($request->validated('search') ?? '');
        $temporaryFile = tempnam(sys_get_temp_dir(), 'certificados-');

        abort_if($temporaryFile === false, 500, 'No se pudo preparar el archivo de exportación.');

        $xlsxPath = $temporaryFile.'.xlsx';
        rename($temporaryFile, $xlsxPath);

        $writer = new Writer;
        $writer->openToFile($xlsxPath);
        $writer->addRow(Row::fromValues([
            'NO',
            'Marca',
            'Modelo',
            'Tipo',
            'Fabricación',
            'Año',
            'NIV',
            'Código',
        ]));

        foreach (
            MsCertificado::query()
                ->search($search)
                ->oldest('id')
                ->cursor() as $certificate
        ) {
            $writer->addRow(Row::fromValues([
                $certificate->no,
                $certificate->marca,
                $certificate->modelo,
                $certificate->tipo,
                $certificate->fabricacion,
                $certificate->anio,
                $certificate->niv,
                $certificate->codigo,
            ]));
        }

        $writer->close();

        return response()
            ->download($xlsxPath, 'maestro-seriales-certificados-'.now()->format('Y-m-d').'.xlsx')
            ->deleteFileAfterSend();
    }
}

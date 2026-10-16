<?php

namespace App\Http\Controllers;

use App\Actions\Certificates\ApplyCertificateDateFilter;
use App\Actions\Certificates\LoadCertificateDocumentSerialCounts;
use App\Http\Requests\ExportCertificatesRequest;
use App\Models\CertificateDocument;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CertificateDocumentExportController extends Controller
{
    public function __invoke(
        ExportCertificatesRequest $request,
        ApplyCertificateDateFilter $dateFilter,
        LoadCertificateDocumentSerialCounts $serialCounts,
    ): BinaryFileResponse {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'reporte-certificados-');
        abort_if($temporaryFile === false, 500, 'No se pudo preparar el archivo de exportación.');

        $xlsxPath = $temporaryFile.'.xlsx';
        rename($temporaryFile, $xlsxPath);
        $writer = new Writer;

        try {
            $writer->openToFile($xlsxPath);

            try {
                $writer->addRow(Row::fromValues([
                    'Certificado',
                    'Gestión',
                    'Cantidad de Seriales en el Certificados',
                    'Cantidad de Seriales Certificados Procesados',
                    'Fecha',
                    'Fecha de certificación',
                    'Fecha de emisión',
                ]));

                CertificateDocument::query()
                    ->with('managements:id,request_date')
                    ->search((string) $request->validated('search', ''))
                    ->tap(fn ($query) => $dateFilter->handle(
                        $query,
                        (string) $request->validated('dateField', ''),
                        (string) $request->validated('dateFrom', ''),
                        (string) $request->validated('dateTo', ''),
                    ))
                    ->latest()
                    ->chunk(200, function (Collection $documents) use ($writer, $serialCounts): void {
                        $serialCounts->handle($documents);

                        foreach ($documents as $document) {
                            $managements = $document->managements
                                ->map(fn ($management): string => 'Gestión #'.$management->id);

                            if ($document->imported_without_management) {
                                $managements->prepend('Carga completa');
                            }

                            $certificationDates = $document->managements->pluck('request_date')
                                ->filter()
                                ->unique(fn ($date): string => $date->format('Y-m-d'))
                                ->sort()
                                ->map(fn ($date): string => $date->format('d/m/Y'));

                            $values = [
                                $document->control_number,
                                $managements->isEmpty() ? 'Sin relación' : $managements->implode(', '),
                                $document->total_serial_count ?? '—',
                                $document->processed_serial_count,
                                $document->created_at?->format('d/m/Y H:i'),
                                $certificationDates->isEmpty() ? 'Sin registrar' : $certificationDates->implode(', '),
                                $document->issued_on?->format('d/m/Y') ?? 'Sin registrar',
                            ];
                            $writer->addRow(new Row(array_map(
                                fn (mixed $value): Cell => is_string($value) ? new StringCell($value) : Cell::fromValue($value),
                                $values,
                            )));
                        }
                    });
            } finally {
                $writer->close();
            }
        } catch (Throwable $exception) {
            unlink($xlsxPath);

            throw $exception;
        }

        return response()
            ->download($xlsxPath, 'reporte-certificados-'.now()->format('Y-m-d').'.xlsx')
            ->deleteFileAfterSend();
    }
}

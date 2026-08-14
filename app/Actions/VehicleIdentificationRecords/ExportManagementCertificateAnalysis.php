<?php

namespace App\Actions\VehicleIdentificationRecords;

use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\VehicleIdentificationRecordCertificateSerialClassification;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportManagementCertificateAnalysis
{
    private const array HEADERS = [
        'Estado',
        'Página',
        'NO',
        'Marca',
        'Modelo',
        'Tipo',
        'Fabricación',
        'Año',
        'NIV',
        'Código',
        'Motivo',
    ];

    public function __construct(
        private HydrateManagementCertificateSourceData $sourceDataHydrator,
        private ProcessManagementCertificates $processor,
    ) {}

    public function handle(
        VehicleIdentificationRecordManagement $management,
        string $category,
    ): BinaryFileResponse {
        $this->sourceDataHydrator->handle($management);
        if ($category === 'missing') {
            $results = collect($this->processor->summary($management)['missingSerials'])
                ->map(fn (array $row): array => [
                    'Sin certificar',
                    null,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $row['serial'],
                    '',
                    'El serial pertenece a la solicitud, pero no aparece en ningún certificado procesado.',
                ])
                ->all();
        } else {
            $classification = $this->classificationFor($category);
            $results = $management->certificates()
                ->with(['serialResults' => fn ($query) => $query
                    ->where('classification', $classification)
                    ->orderBy('id')])
                ->orderBy('id')
                ->get()
                ->flatMap(fn ($certificate) => $certificate->serialResults
                    ->when($category === 'duplicates', fn ($serialResults) => $serialResults->filter(
                        fn ($result): bool => (bool) ($result->source_data['_requested']
                            ?? ! str_contains($result->reason ?? '', 'no solicitado')),
                    ))
                    ->flatMap(
                        fn ($result): array => $this->spreadsheetRow($category, $certificate->control_number, $result),
                    ))
                ->all();
        }

        if ($results === []) {
            throw new RuntimeException('No hay registros disponibles para esta exportación.');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'gestion-certificados-');

        if ($temporaryFile === false) {
            throw new RuntimeException('No se pudo preparar el archivo de exportación.');
        }

        $xlsxPath = $temporaryFile.'.xlsx';
        rename($temporaryFile, $xlsxPath);
        $writer = new Writer;
        $writer->openToFile($xlsxPath);
        $writer->addRow(Row::fromValues(self::HEADERS));

        foreach ($results as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()
            ->download(
                $xlsxPath,
                "gestion-{$management->id}-{$category}-".now()->format('Y-m-d-His').'.xlsx',
            )
            ->deleteFileAfterSend();
    }

    private function classificationFor(string $category): VehicleIdentificationRecordCertificateSerialClassification
    {
        return match ($category) {
            'certified' => VehicleIdentificationRecordCertificateSerialClassification::Certified,
            'duplicates' => VehicleIdentificationRecordCertificateSerialClassification::Duplicate,
            'unexpected' => VehicleIdentificationRecordCertificateSerialClassification::Unexpected,
            'invalid' => VehicleIdentificationRecordCertificateSerialClassification::Invalid,
            default => throw new RuntimeException('La categoría de exportación no es válida.'),
        };
    }

    /** @return list<list<int|string|null>> */
    private function spreadsheetRow(
        string $category,
        string $controlNumber,
        VehicleIdentificationRecordCertificateSerial $result,
    ): array
    {
        $source = $result->source_data ?? [];

        if ($category === 'invalid') {
            $values = array_pad($source['values'] ?? [], 7, '');
            $row = [
                'Inválido',
                $source['page'] ?? null,
                ...array_slice($values, 0, 7),
                $controlNumber,
                $result->reason,
            ];
        } else {
            $row = [
                match ($category) {
                    'certified' => 'Certificado',
                    'duplicates' => 'Duplicado',
                    'unexpected' => 'No solicitado',
                    default => $category,
                },
                null,
                $source['no'] ?? '',
                $source['marca'] ?? '',
                $source['modelo'] ?? '',
                $source['tipo'] ?? '',
                $source['fabricacion'] ?? '',
                $source['anio'] ?? '',
                $result->serial ?? ($source['niv'] ?? ''),
                $source['codigo'] ?? $controlNumber,
                $result->reason ?? '',
            ];
        }

        return array_fill(0, max(1, $result->occurrences), $row);
    }
}

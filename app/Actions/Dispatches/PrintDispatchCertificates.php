<?php

namespace App\Actions\Dispatches;

use App\Models\CertificateDocument;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PdfDecompressor\Normalizer;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class PrintDispatchCertificates
{
    public function handle(Dispatch $dispatch): string
    {
        $lines = $dispatch->lines()->with('certificate')->orderBy('id')->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException('El despacho no tiene NIV.');
        }

        /** @var array<string, list<MsCertificado>> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $certificate = $line->certificate;

            if ($certificate === null) {
                continue;
            }

            $codigo = Str::upper(trim($certificate->codigo));

            if ($codigo === '') {
                continue;
            }

            $groups[$codigo][] = $certificate;
        }

        if ($groups === []) {
            throw new RuntimeException('No se encontraron certificados para los NIV del despacho.');
        }

        $pdf = new Fpdi;

        foreach (array_keys($groups) as $codigo) {
            $document = CertificateDocument::query()
                ->whereRaw('UPPER(TRIM(control_number)) = ?', [$codigo])
                ->oldest('id')
                ->first();

            if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
                throw new RuntimeException("No se encontró el certificado {$codigo}.");
            }

            $path = Storage::disk('local')->path($document->file_path);
            $path = $this->normalizePdf($path);
            $pdf->setSourceFile($path);
            $this->appendImportedPage($pdf, 1);
        }

        $this->appendSerialSummary($pdf, $lines->pluck('certificate')->filter()->values());

        return $pdf->Output('S');
    }

    private function normalizePdf(string $path): string
    {
        $bytes = file_get_contents($path);

        if ($bytes === false || ! Normalizer::isCompressed($bytes)) {
            return $path;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'pdf_normalized_');

        if ($temporaryFile === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para el certificado.');
        }

        file_put_contents($temporaryFile, (new Normalizer)->normalize($bytes));

        return $temporaryFile;
    }

    /** @param Collection<int, MsCertificado> $certificates */
    private function appendSerialSummary(Fpdi $pdf, Collection $certificates): void
    {
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage('P', 'A4');
        $this->renderSummaryHeading($pdf, $certificates);
        $this->renderTableHeader($pdf);

        foreach ($certificates as $certificate) {
            if ($pdf->GetY() + 7 > 282) {
                $pdf->AddPage('P', 'A4');
                $this->renderSummaryHeading($pdf, $certificates);
                $this->renderTableHeader($pdf);
            }

            $this->renderCertificateRow($pdf, $certificate);
        }
    }

    /** @param Collection<int, MsCertificado> $certificates */
    private function renderSummaryHeading(Fpdi $pdf, Collection $certificates): void
    {
        $controlNumbers = $certificates
            ->pluck('codigo')
            ->map(fn (string $codigo): string => Str::upper(trim($codigo)))
            ->unique()
            ->implode(', ');

        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(190, 6, $this->encode('NÚMEROS DE CONTROL: '.$controlNumbers));
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(190, 5, $this->encode('FABRICADO POR: CORPORACION KURI SAM, C.A.'), 0, 1);
        $pdf->Cell(190, 5, 'Nro. RIF J-31447419-7', 0, 1);
        $pdf->Ln(3);
    }

    private function renderTableHeader(Fpdi $pdf): void
    {
        $widths = [8, 21, 25, 20, 20, 13, 38, 45];
        $labels = ['#', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV', 'Número de control'];

        $pdf->SetFont('Helvetica', 'B', 6.5);
        $pdf->SetFillColor(224, 228, 233);
        $pdf->SetDrawColor(150, 156, 165);

        foreach ($labels as $index => $label) {
            $pdf->Cell($widths[$index], 7, $this->encode($label), 1, $index === array_key_last($labels) ? 1 : 0, 'L', true);
        }
    }

    private function renderCertificateRow(Fpdi $pdf, MsCertificado $certificate): void
    {
        $widths = [8, 21, 25, 20, 20, 13, 38, 45];
        $values = [
            $certificate->no,
            Str::limit($certificate->marca, 13),
            Str::limit($certificate->modelo, 16),
            Str::limit($certificate->tipo, 12),
            Str::limit($certificate->fabricacion, 12),
            (string) $certificate->anio,
            $certificate->niv,
            $certificate->codigo,
        ];

        $pdf->SetFont('Helvetica', '', 6.5);

        foreach ($values as $index => $value) {
            $pdf->Cell($widths[$index], 7, $this->encode((string) $value), 1, $index === array_key_last($values) ? 1 : 0);
        }
    }

    private function appendImportedPage(Fpdi $pdf, int $pageNumber): void
    {
        $templateId = $pdf->importPage($pageNumber);
        $size = $pdf->getTemplateSize($templateId);
        $orientation = $size['orientation'] ?? ($size['width'] >= $size['height'] ? 'L' : 'P');

        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useImportedPage($templateId);
    }

    private function encode(string $value): string
    {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }
}

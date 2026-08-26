<?php

namespace App\Actions\Dispatches;

use App\Models\Dispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class PrintDispatchUncertifiedSerials
{
    public function handle(Dispatch $dispatch): string
    {
        $lines = $dispatch->uncertifiedLines()
            ->with('product:id,name')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException('Este despacho no tiene seriales sin certificar.');
        }

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(15, 12, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $this->renderDocumentHeading($pdf, $dispatch);
        $this->renderProductSummary($pdf, $lines);
        $this->renderSerialDetails($pdf, $lines, $dispatch);
        $this->renderFooter($pdf);

        return $pdf->Output('S');
    }

    private function renderDocumentHeading(\FPDF $pdf, Dispatch $dispatch): void
    {
        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(88, 6, $this->encode('Dirección del Cliente:'), 0, 0);
        $pdf->Cell(4);
        $pdf->Cell(88, 6, $this->encode('Dirección de Envío:'), 0, 1);

        $pdf->SetFont('Helvetica', '', 8);
        $left = [
            'MANAR MOTOS COMPAÑIA ANONIMA',
            'Avenida Upata c/c Calle Cuyuní Parcela 1-C',
            'Edificio Chico P.B. Locales 1; 2 y 3',
            'Ciudad Bolívar, Venezuela',
            'NIF: J-401790305',
        ];
        $right = [
            'MANAR MOTOS COMPAÑIA ANONIMA',
            'Avenida Upata c/c Calle Cuyuní Parcela 1-C',
            'Edificio Chico P.B. Locales 1; 2 y 3',
            'Ciudad Bolívar, Venezuela',
            '',
        ];

        foreach ($left as $index => $text) {
            $pdf->Cell(88, 4.5, $this->encode($text), 0, 0);
            $pdf->Cell(4);
            $pdf->Cell(88, 4.5, $this->encode($right[$index]), 0, 1);
        }

        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell(180, 8, $this->encode($dispatch->name), 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(180, 5, $this->encode('Fecha de despacho: '.($dispatch->finalized_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'))), 0, 1, 'R');
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetTextColor(40, 63, 92);
        $pdf->Cell(180, 9, $this->encode('Reporte de seriales no certificados despachados'), 0, 1, 'C');
        $pdf->SetTextColor(35, 35, 35);
        $pdf->Ln(4);
    }

    /** @param Collection<int, \App\Models\DispatchUncertifiedLine> $lines */
    private function renderProductSummary(\FPDF $pdf, Collection $lines): void
    {
        $this->sectionTitle($pdf, 'Resumen de productos');
        $this->tableHeader($pdf, [130, 50], ['Producto', 'Total']);

        $groups = $lines->groupBy(fn ($line): string => $line->product?->name ?? 'Producto no identificado');

        foreach ($groups as $productName => $productLines) {
            $this->ensureSpace($pdf, 8, fn () => $this->tableHeader($pdf, [130, 50], ['Producto', 'Total']));
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(130, 7, $this->encode(Str::limit($productName, 75)), 1, 0);
            $pdf->Cell(50, 7, (string) $productLines->count(), 1, 1, 'R');
        }

        $pdf->Ln(7);
    }

    /**
     * @param Collection<int, \App\Models\DispatchUncertifiedLine> $lines
     */
    private function renderSerialDetails(\FPDF $pdf, Collection $lines, Dispatch $dispatch): void
    {
        $this->sectionTitle($pdf, 'Detalle de seriales');
        $header = fn () => $this->tableHeader($pdf, [105, 55, 20], ['Producto', 'Lote/Nº de serie', 'Entregado']);
        $header();

        foreach ($lines as $line) {
            $this->ensureSpace($pdf, 8, $header);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(105, 7, $this->encode(Str::limit($line->product?->name ?? 'Producto no identificado', 58)), 1, 0);
            $pdf->SetFont('Courier', '', 8.5);
            $pdf->Cell(55, 7, $line->niv, 1, 0);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(20, 7, '1', 1, 1, 'R');
        }

        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(180, 5, $this->encode('Total despachado sin certificado: '.$lines->count().' serial(es).'), 0, 1, 'R');
        $pdf->Cell(180, 5, $this->encode('Despacho: '.$dispatch->name), 0, 1, 'R');
    }

    private function sectionTitle(\FPDF $pdf, string $title): void
    {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(235, 238, 242);
        $pdf->Cell(180, 7, $this->encode($title), 0, 1, 'L', true);
    }

    /** @param list<int> $widths @param list<string> $labels */
    private function tableHeader(\FPDF $pdf, array $widths, array $labels): void
    {
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetFillColor(224, 228, 233);
        $pdf->SetDrawColor(165, 171, 180);

        foreach ($labels as $index => $label) {
            $pdf->Cell($widths[$index], 7, $this->encode($label), 1, $index === array_key_last($labels) ? 1 : 0, 'L', true);
        }
    }

    private function ensureSpace(\FPDF $pdf, float $height, callable $afterPageBreak): void
    {
        if ($pdf->GetY() + $height <= 275) {
            return;
        }

        $pdf->AddPage();
        $afterPageBreak();
    }

    private function renderFooter(\FPDF $pdf): void
    {
        $pdf->SetY(-14);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(120, 5, $this->encode('CORPORACION KURI SAM, C.A. | Venezuela | proyectos@corporacioneureka.com'), 0, 0);
        $pdf->Cell(60, 5, $this->encode('Página '.$pdf->PageNo().'/{nb}'), 0, 0, 'R');
    }

    private function encode(string $value): string
    {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }
}

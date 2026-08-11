<?php

namespace App\Actions\Dispatches;

use App\Models\CertificateDocument;
use App\Models\Dispatch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PdfDecompressor\Normalizer;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;

class PrintDispatchCertificates
{
    public function handle(Dispatch $dispatch): string
    {
        $lines = $dispatch->lines()->with('certificate')->orderBy('id')->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException('El despacho no tiene NIV.');
        }

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

            $groups[$codigo][] = $certificate->niv;
        }

        if ($groups === []) {
            throw new RuntimeException('No se encontraron certificados para los NIV del despacho.');
        }

        $pdf = new Fpdi;

        foreach ($groups as $codigo => $serials) {
            $document = CertificateDocument::query()
                ->whereRaw('UPPER(TRIM(control_number)) = ?', [$codigo])
                ->oldest('id')
                ->first();

            if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
                throw new RuntimeException("No se encontró el certificado {$codigo}.");
            }

            $path = Storage::disk('local')->path($document->file_path);
            $path = $this->normalizePdf($path);
            $pageNumbers = $this->resolvePages($path, $serials);
            $pdf->setSourceFile($path);

            foreach ($pageNumbers as $pageNumber) {
                $this->appendImportedPage($pdf, $pageNumber);
            }
        }

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

    /**
     * @param  list<string>  $serials
     * @return list<int>
     */
    private function resolvePages(string $path, array $serials): array
    {
        $parser = new Parser;
        $document = $parser->parseFile($path);
        $normalizedSerials = collect($serials)
            ->map(fn (string $serial): string => $this->normalize($serial))
            ->unique();

        $pages = [];

        foreach ($document->getPages() as $index => $page) {
            $text = $this->normalize($page->getText());

            if ($text === '') {
                continue;
            }

            foreach ($normalizedSerials as $serial) {
                if ($serial !== '' && str_contains($text, $serial)) {
                    $pages[] = $index + 1;

                    break;
                }
            }
        }

        $pageNumbers = array_values(array_unique($pages));
        sort($pageNumbers);

        if ($pageNumbers === [] || $pageNumbers[0] !== 1) {
            array_unshift($pageNumbers, 1);
        }

        return $pageNumbers;
    }

    private function appendImportedPage(Fpdi $pdf, int $pageNumber): void
    {
        $templateId = $pdf->importPage($pageNumber);
        $size = $pdf->getTemplateSize($templateId);
        $orientation = $size['orientation'] ?? ($size['width'] >= $size['height'] ? 'L' : 'P');

        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useImportedPage($templateId);
    }

    private function normalize(string $value): string
    {
        return Str::upper(preg_replace('/\s+/u', '', $value) ?? '');
    }
}

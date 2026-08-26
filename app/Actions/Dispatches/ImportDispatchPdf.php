<?php

namespace App\Actions\Dispatches;

use App\CertificateStatus;
use App\Models\MsCertificado;
use App\Models\Product;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

class ImportDispatchPdf
{
    /**
     * @return array{
     *     records: array<int, array<string, mixed>>,
     *     dispatchName: ?string,
     *     detected: int,
     *     notFound: list<string>,
     *     notFoundRecords: list<array{niv: string, product_id: int|null, product_name: string|null, first_value: string|null, second_value: string|null, product_niv: string, year: int|null}>,
     *     alreadyDispatched: list<string>
     * }
     */
    public function handle(string $filePath): array
    {
        set_time_limit(0);

        $config = new Config;
        $config->setDataTmFontInfoHasToBeIncluded(true);
        $document = (new Parser([], $config))->parseFile($filePath);
        $this->normalizeDocumentDetails($document);
        $text = $document->getText();

        $nivs = [];
        preg_match_all('/\b[A-HJ-NPR-Z0-9]{17}\b/', Str::upper($text), $matches);

        foreach ($matches[0] as $niv) {
            $nivs[$niv] = true;
        }

        $nivList = array_keys($nivs);
        $records = collect();
        $foundByNiv = [];

        foreach (array_chunk($nivList, 500) as $chunk) {
            $found = MsCertificado::query()
                ->whereIn('niv', $chunk)
                ->get(['id', 'niv', 'marca', 'modelo', 'codigo', 'status']);

            foreach ($found as $certificado) {
                $foundByNiv[$certificado->niv] = $certificado->status->value;
            }

            $records->push(...$found
                ->where('status', CertificateStatus::PendingDispatch)
                ->all());
        }

        $notFound = array_values(array_filter(
            $nivList,
            fn (string $niv): bool => ! array_key_exists($niv, $foundByNiv),
        ));
        $notFoundRecords = $this->buildNotFoundRecords($notFound);
        $alreadyDispatched = array_values(array_filter(
            $nivList,
            fn (string $niv): bool => array_key_exists($niv, $foundByNiv)
                && $foundByNiv[$niv] !== CertificateStatus::PendingDispatch->value,
        ));

        if ($nivList === []) {
            throw new RuntimeException('No se encontraron seriales de 17 caracteres en el PDF.');
        }

        return [
            'records' => $records->unique('id')->values()->map->toArray()->all(),
            'dispatchName' => $this->extractDispatchName($text),
            'detected' => count($nivList),
            'notFound' => $notFound,
            'notFoundRecords' => $notFoundRecords,
            'alreadyDispatched' => $alreadyDispatched,
        ];
    }

    /**
     * @param  list<string>  $nivs
     * @return list<array{niv: string, product_id: int|null, product_name: string|null, first_value: string|null, second_value: string|null, product_niv: string, year: int|null}>
     */
    private function buildNotFoundRecords(array $nivs): array
    {
        $productCodes = collect($nivs)
            ->map(fn (string $niv): string => Str::upper(Str::substr($niv, 3, 2)))
            ->unique()
            ->values();
        $productsByNiv = Product::query()
            ->whereNotNull('niv')
            ->orderBy('id')
            ->get(['id', 'name', 'first_value', 'second_value', 'niv', 'year'])
            ->filter(fn (Product $product): bool => $productCodes->contains(Str::upper(trim((string) $product->niv))))
            ->groupBy(fn (Product $product): string => Str::upper(trim((string) $product->niv)));

        return collect($nivs)->map(function (string $niv) use ($productsByNiv): array {
            $productCode = Str::upper(Str::substr($niv, 3, 2));
            $product = $productsByNiv->get($productCode)?->first();

            return [
                'niv' => $niv,
                'product_id' => $product?->id,
                'product_name' => $product?->name,
                'first_value' => $product?->first_value,
                'second_value' => $product?->second_value,
                'product_niv' => $productCode,
                'year' => $product?->year,
            ];
        })->all();
    }

    private function extractDispatchName(string $text): ?string
    {
        if (
            preg_match(
                '/PLM[\s\/\-]*PT[\s\/\-]*LM[\s\/\-]*OUT[\s\/\-]*\d+/i',
                $text,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return Str::of($matches[0])
            ->upper()
            ->replaceMatches('/\s+/u', '')
            ->toString();
    }

    private function normalizeDocumentDetails(Document $document): void
    {
        try {
            $refProp = new \ReflectionProperty($document, 'details');
            $refProp->setAccessible(true);
            $details = $refProp->getValue($document);

            if (is_array($details) && isset($details['Producer']) && str_starts_with((string) $details['Producer'], 'FPDF')) {
                $details['Producer'] = 'Disabled_FPDF_Workaround';
                $refProp->setValue($document, $details);
            }
        } catch (\Throwable) {
            // Silently ignore if reflection is unavailable
        }
    }
}

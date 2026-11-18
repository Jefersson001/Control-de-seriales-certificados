<?php

namespace App\Actions\Dispatches;

use App\Actions\Products\MatchProductSerial;
use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\DispatchUncertifiedLine;
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
                if (($foundByNiv[$certificado->niv] ?? null) !== CertificateStatus::Dispatched->value) {
                    $foundByNiv[$certificado->niv] = $certificado->status->value;
                }
            }

            $records->push(...$found
                ->whereIn('status', [CertificateStatus::PendingDispatch, CertificateStatus::Returned])
                ->all());
        }
        $records = $records->reject(fn (MsCertificado $record): bool => ($foundByNiv[$record->niv] ?? null) === CertificateStatus::Dispatched->value);
        if ($records->groupBy('niv')->contains(fn ($group): bool => $group->count() > 1)) {
            throw new RuntimeException('Hay NIV repetidos en el maestro. Revisa los duplicados antes de cargar el despacho.');
        }

        $notFound = array_values(array_filter(
            $nivList,
            fn (string $niv): bool => ! array_key_exists($niv, $foundByNiv),
        ));
        $previouslyUncertified = DispatchUncertifiedLine::query()->whereIn('niv', $notFound)
            ->whereHas('dispatch', fn ($query) => $query->where('status', DispatchStatus::Done))->pluck('niv')->all();
        $notFound = array_values(array_diff($notFound, $previouslyUncertified));
        $notFoundRecords = $this->buildNotFoundRecords($notFound);
        $alreadyDispatched = array_values(array_filter(
            $nivList,
            fn (string $niv): bool => array_key_exists($niv, $foundByNiv)
                && ! in_array($foundByNiv[$niv], [CertificateStatus::PendingDispatch->value, CertificateStatus::Returned->value], true),
        ));
        $alreadyDispatched = array_values(array_unique([...$alreadyDispatched, ...$previouslyUncertified]));

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
        $matcher = app(MatchProductSerial::class);
        $products = Product::query()
            ->whereNotNull('niv')
            ->orderBy('id')
            ->get(['id', 'name', 'brand', 'category', 'first_value', 'second_value', 'niv', 'year']);

        return array_values(collect($nivs)->map(function (string $niv) use ($products, $matcher): array {
            $brand = $matcher->brand($niv);
            $productCode = $matcher->code($niv, $brand ?? 'bera') ?? substr($niv, 0, 8);
            $matches = $products->filter(fn (Product $product): bool => $brand !== null && $product->category === $matcher->category($niv) && $matcher->handle($product, $niv));
            if ($matches->count() > 1) {
                throw new RuntimeException("El NIV {$niv} coincide con varios productos. Revisa sus códigos antes de cargar el despacho.");
            }
            $product = $matches->first();

            return [
                'niv' => $niv,
                'product_id' => $product?->id,
                'product_name' => $product?->name,
                'first_value' => $product?->first_value,
                'second_value' => $product?->second_value,
                'product_niv' => $productCode,
                'year' => $product?->year,
            ];
        })->all());
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

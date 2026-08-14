<?php

namespace App\Actions\Dispatches;

use App\CertificateStatus;
use App\Models\MsCertificado;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class ImportDispatchPdf
{
    /**
     * @return array{
     *     records: array<int, array<string, mixed>>,
     *     dispatchName: ?string,
     *     detected: int,
     *     notFound: list<string>,
     *     alreadyDispatched: list<string>
     * }
     */
    public function handle(string $filePath): array
    {
        set_time_limit(0);

        $config = new Config;
        $config->setDataTmFontInfoHasToBeIncluded(true);
        $text = (new Parser([], $config))->parseFile($filePath)->getText();

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
            'alreadyDispatched' => $alreadyDispatched,
        ];
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
}

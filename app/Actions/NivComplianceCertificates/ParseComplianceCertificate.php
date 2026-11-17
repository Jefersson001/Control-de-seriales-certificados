<?php

namespace App\Actions\NivComplianceCertificates;

use App\Models\NivComplianceCertificate;
use Illuminate\Support\Str;
use RuntimeException;

class ParseComplianceCertificate
{
    public function __construct(private ExtractCompliancePdfText $extractor) {}

    /** @return array{fields: array<string, mixed>, text: string, missing_fields: list<string>} */
    public function handle(string $path): array
    {
        return $this->parse($this->extractor->handle($path));
    }

    /**
     * @param  list<array{page: int, text: string, regions?: array<string, string>}>  $pages
     * @return array{fields: array<string, mixed>, text: string, missing_fields: list<string>}
     */
    public function parse(array $pages): array
    {
        $normalized = array_map(fn (array $page): string => $this->normalize($page['text']."\n".implode("\n", $page['regions'] ?? [])), $pages);
        $text = implode("\n\n", $normalized);
        if (! str_contains($text, 'CONSTANCIA') || (! str_contains($text, 'CUMPLIMIENTO') && ! str_contains($text, 'FE DE ERRATA'))) {
            throw new RuntimeException('El archivo no contiene una constancia de cumplimiento de composición y ubicación del NIV legible.');
        }

        $erratum = collect($normalized)->first(fn (string $page): bool => str_contains($page, 'FE DE ERRATA'));
        $original = collect($normalized)->first(fn (string $page): bool => ! str_contains($page, 'FE DE ERRATA')) ?? $normalized[0];
        $fields = array_fill_keys(array_keys(NivComplianceCertificate::fieldLabels()), null);
        $fields['has_erratum'] = $erratum !== null;
        $fields['certificate_number'] = $this->match('/^N[O0°º?]*\s*(\d{3,})\b/mu', $original);
        $reference = $this->match('/\b([I1][O0]\s*CT\s*R\s*\d+\s+\d+\s+\d+)\b/u', $original);
        $fields['document_reference'] = $reference === null ? null : preg_replace('/^[I1][O0]/', 'IO', $reference);
        $company = $this->match('/EMPRESA\s*:\s*(.+?)\s+IDENTIFICADA\b/su', $original);
        $fields['company_name'] = $company === null ? null : preg_replace('/\s+/', ' ', $company);
        $companySection = $this->match('/EMPRESA\s*:\s*(.{0,500})/su', $original) ?? ($erratum ?? $original);
        $erratumCompany = str_contains($erratum ?? '', 'CIUDADANO') ? Str::after($erratum, 'CIUDADANO') : '';
        foreach ([$companySection, $erratumCompany] as $section) {
            if (preg_match('/\b([JVEG])[.,\s-]*(\d{8})\s*-\s*(\d)\b/u', $section, $rif)) {
                $fields['company_rif'] = "{$rif[1]}-{$rif[2]}-{$rif[3]}";

                break;
            }
        }
        $fields['refeciv'] = $this->match('/\b(AG[A-Z0-9]{4,10})\b/u', $text);
        $fields['serial_pattern'] = $this->serialPattern($text);
        $fields['brand'] = $this->field($original, 'MARCA', 'USO');
        $fields['model'] = $this->field($original, 'MODELO', 'CLASE');
        $fields['series_version'] = $this->field($original, 'SERIE\s*\/\s*VERSION', 'TIPO');
        $fields['country_of_origin'] = $this->field($original, 'PAIS\s+(?:DE\s+)?ORIGEN', 'IIF|IIM|IFE|IIE');
        $fields['vehicle_use'] = $this->match('/\bUSO\s*[:\-]?\s*([A-Z]+)\b/u', $original);
        $fields['vehicle_class'] = $this->match('/\bCLASE\s*[:\-]?\s*([A-Z]+)\b/u', $original);
        $fields['vehicle_type'] = $this->match('/\bTIPO\s*[:\-]?\s*([A-Z]+)\b/u', $original);
        $fields['wmi'] = $fields['serial_pattern'] === null ? $this->match('/\bIMF[^\n]{0,18}\s+([A-HJ-NPR-Z0-9]{3})\b/u', $original) : substr($fields['serial_pattern'], 0, 3);
        if (preg_match('/\bIIF[^\n]{0,25}?\bN[\/I]A\b/u', $text)) {
            $fields['iim'] = 'N/A';
        }
        $fields['observations'] = $this->match('/^OBSERVACIONES\s*:\s*([^\n]+)/mu', $original);
        if (preg_match_all('/\b(20\d{2})\s*\/\s*(20\d{2})\b/u', $text, $years, PREG_SET_ORDER)) {
            $fields['manufacturing_years'] = implode(', ', array_unique(array_column($years, 1)));
            $modelYears = array_unique(array_column($years, 2));
            $fields['model_year'] = count($modelYears) === 1 ? (int) reset($modelYears) : null;
        }

        if (preg_match('/A LOS\s+(\d{2})\s+DI[A-Z\s]{0,5}DEL\s+MES\s+DE\s+([A-Z]+)\s+DE\s+(20\d{2})/u', $original, $date)) {
            $fields['issued_on'] = $this->date($date[1], $date[2], $date[3]);
        }
        if (preg_match('/HASTA EL\s+(\d{1,2})\s+DE\s+([A-Z]+)[\s\S]{0,200}?DEL\s+ANO\s+(20\d{2})/u', $original, $date)) {
            $fields['expires_on'] = $this->date($date[1], $date[2], $date[3]);
        }

        if ($erratum !== null) {
            $fields['original_series_version'] = $fields['series_version'];
            $fields['series_version'] = $this->field($erratum, 'SERIE\s*\/\s*VERSION', 'TIPO') ?? $fields['series_version'];
            $fields['erratum_reference'] = $this->match('/(INTT\s*\/\s*GGTT\s*\d+\s+20\d{2})/u', $erratum);
            $fields['erratum_notes'] = $this->match('/(DONDE DICE[\s\S]{1,350}?DEBE DECIR[\s\S]{1,150}?)(?:\.\s+LA\s+PRESENTE|\nPRESENTE)/u', $erratum);
            if (preg_match('/CARACAS\s*,\s*(\d{1,2})\s+DE\s+([A-Z]+)\s+DE\s+(20\d{2})/u', $erratum, $date)) {
                $fields['erratum_issued_on'] = $this->date($date[1], $date[2], $date[3]);
            }
            if ($fields['issued_on'] === null && preg_match('/EN\s+FECHA\s+(\d{1,2})\s+DE\s+([A-Z]+)\s+DEL\s+(?:ANO\s+)?(20\d{2})/u', $erratum, $date)) {
                $fields['issued_on'] = $this->date($date[1], $date[2], $date[3]);
            }
        }

        $missing = array_keys(array_filter($fields, fn (mixed $value): bool => $value === null || $value === ''));
        if ($erratum === null) {
            $missing = array_values(array_diff($missing, ['original_series_version', 'erratum_reference', 'erratum_issued_on', 'erratum_notes']));
        }

        return ['fields' => $fields, 'text' => $text, 'missing_fields' => $missing];
    }

    private function normalize(string $text): string
    {
        return implode("\n", array_map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', Str::upper(Str::ascii($line)))), preg_split('/\R/u', $text) ?: []));
    }

    private function match(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $match) ? trim($match[1], " \n\t:;.") : null;
    }

    private function field(string $text, string $label, string $nextLabel): ?string
    {
        return $this->match('/^'.$label.'\s*[:\-]?\s*([^\n]+?)(?=\s+(?:'.$nextLabel.')\b|\n|$)/mu', $text);
    }

    private function serialPattern(string $text): ?string
    {
        preg_match_all('/(?<![A-Z0-9])[A-Z0-9]{17}(?![A-Z0-9])/', $text, $matches);
        $candidates = [];
        $verifiedCandidates = [];
        foreach ($matches[0] as $value) {
            if (! preg_match('/[A-Z]/', substr($value, 0, 3))) {
                continue;
            }
            $value[8] = $value[8] === 'O' ? '0' : $value[8];
            $value = substr($value, 0, 11).strtr(substr($value, 11), ['O' => '0', 'I' => '1', 'L' => '1']);
            $alternatives = [$value];
            if (in_array($value[10], ['I', 'L', 'O'], true)) {
                $alternative = $value;
                $alternative[10] = strtr($value[10], ['I' => '1', 'L' => '1', 'O' => '0']);
                if ($this->hasValidCheckDigit($alternative)) {
                    $alternatives[] = $alternative;
                }
            }
            foreach ($alternatives as $candidate) {
                if (preg_match('/^[A-HJ-NPR-Z0-9]{8}[0-9X][A-HJ-NPR-Z0-9]{2}[0-9]{6}$/', $candidate)) {
                    $candidates[] = $candidate;
                    if ($this->hasValidCheckDigit($candidate)) {
                        $verifiedCandidates[] = $candidate;
                    }
                }
            }
        }
        $counts = array_count_values($verifiedCandidates === [] ? $candidates : $verifiedCandidates);
        arsort($counts);

        return array_key_first($counts);
    }

    private function hasValidCheckDigit(string $serial): bool
    {
        $values = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8, 'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9, 'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9];
        $weights = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach (str_split($serial) as $position => $character) {
            $value = ctype_digit($character) ? (int) $character : ($values[$character] ?? null);
            if ($value === null) {
                return false;
            }
            $sum += $value * $weights[$position];
        }
        $remainder = $sum % 11;

        return $serial[8] === ($remainder === 10 ? 'X' : (string) $remainder);
    }

    private function date(string $day, string $month, string $year): ?string
    {
        $months = ['ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7, 'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12];
        $number = $months[$month] ?? 0;

        return checkdate($number, (int) $day, (int) $year) ? sprintf('%04d-%02d-%02d', $year, $number, $day) : null;
    }
}

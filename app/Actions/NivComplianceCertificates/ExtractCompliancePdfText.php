<?php

namespace App\Actions\NivComplianceCertificates;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser;

class ExtractCompliancePdfText
{
    /** @return list<array{page: int, text: string, regions?: array<string, string>}> */
    public function handle(string $path): array
    {
        $document = (new Parser)->parseFile($path);
        $pages = $document->getPages();
        if (count($pages) > 10) {
            throw new RuntimeException('La constancia no puede superar las 10 páginas.');
        }

        $texts = array_map(fn ($page, int $index): array => ['page' => $index + 1, 'text' => $page->getText()], $pages, array_keys($pages));
        if (count(array_filter($texts, fn (array $page): bool => mb_strlen(trim($page['text'])) > 100)) === count($pages) && $pages !== []) {
            return $texts;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Este PDF es escaneado y requiere el reconocimiento de texto de Windows. El servidor actual no dispone de ese motor.');
        }

        set_time_limit(150);
        $temporaryPath = 'niv-compliance-ocr/'.Str::uuid().'.pdf';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer el archivo PDF.');
        }
        try {
            if (! Storage::disk('local')->put($temporaryPath, $contents)) {
                throw new RuntimeException('No se pudo preparar el PDF para reconocer su texto.');
            }
            $result = Process::timeout(120)->env([
                'SystemRoot' => getenv('SystemRoot') ?: getenv('windir') ?: 'C:\\Windows',
                'TEMP' => sys_get_temp_dir(),
                'TMP' => sys_get_temp_dir(),
            ])->run([
                'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', resource_path('ocr/read-compliance-pdf.ps1'), '-Path', Storage::disk('local')->path($temporaryPath),
            ]);
        } finally {
            Storage::disk('local')->delete($temporaryPath);
        }

        if (! $result->successful()) {
            Log::warning('NIV compliance OCR failed.', ['exit_code' => $result->exitCode(), 'error' => $result->errorOutput()]);
            throw new RuntimeException('No fue posible reconocer el texto del PDF. Verifica la calidad del escaneo y que el reconocimiento de texto de Windows esté disponible.');
        }

        $data = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data['pages'] ?? null) || $data['pages'] === []) {
            throw new RuntimeException('No se encontró texto legible en la constancia.');
        }

        $parsedPages = [];
        foreach ($data['pages'] as $page) {
            if (! is_array($page) || ! is_int($page['page'] ?? null) || ! is_string($page['text'] ?? null)) {
                throw new RuntimeException('El reconocimiento de texto devolvió una página no válida.');
            }
            $regions = [];
            foreach (is_array($page['regions'] ?? null) ? $page['regions'] : [] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $regions[$key] = $value;
                }
            }
            $parsedPages[] = ['page' => $page['page'], 'text' => $page['text'], 'regions' => $regions];
        }

        return $parsedPages;
    }
}

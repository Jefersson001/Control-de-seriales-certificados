<?php

namespace App\Actions\Certificates;

use App\Models\CertificateDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StoreCertificateDocument
{
    public function handle(UploadedFile $pdf, string $controlNumber, ?int $managementId = null, ?string $issuedOn = null): CertificateDocument
    {
        return $this->storeOnce(
            $controlNumber, $pdf->getClientOriginalName(), $managementId,
            fn (string $path): bool => (bool) Storage::disk('local')->putFileAs(
                dirname($path), $pdf, basename($path),
            ),
            $issuedOn,
        );
    }

    public function handleStoredFile(
        string $sourcePath,
        string $originalFileName,
        string $controlNumber,
        int $managementId,
        ?string $issuedOn = null,
    ): CertificateDocument {
        return $this->storeOnce(
            $controlNumber, $originalFileName, $managementId,
            function (string $path) use ($sourcePath): bool {
                $stream = Storage::disk('local')->readStream($sourcePath);

                if ($stream === false) {
                    return false;
                }

                try {
                    return Storage::disk('local')->put($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            $issuedOn,
        );
    }

    /** @param callable(string): bool $writeFile */
    private function storeOnce(
        string $controlNumber,
        string $originalFileName,
        ?int $managementId,
        callable $writeFile,
        ?string $issuedOn = null,
    ): CertificateDocument {
        $normalizedControlNumber = Str::upper(trim($controlNumber));

        return Cache::lock('certificate-document:'.hash('sha256', $normalizedControlNumber), 15)->block(
            5,
            function () use ($normalizedControlNumber, $originalFileName, $managementId, $writeFile, $issuedOn): CertificateDocument {
                $existing = CertificateDocument::query()
                    ->whereRaw('UPPER(TRIM(control_number)) = ?', [$normalizedControlNumber])
                    ->oldest('id')
                    ->first();

                if ($existing !== null) {
                    if ($issuedOn !== null && $existing->issued_on === null) {
                        $existing->update(['issued_on' => $issuedOn]);
                    }

                    if ($managementId === null && ! $existing->imported_without_management) {
                        $existing->update(['imported_without_management' => true]);
                    }

                    if ($managementId !== null) {
                        $existing->managements()->syncWithoutDetaching([$managementId]);
                    }

                    return $existing->refresh()->load('managements');
                }

                $downloadName = $normalizedControlNumber.'.pdf';
                $path = 'certificate-documents/'.Str::uuid().'.pdf';

                if (! $writeFile($path)) {
                    throw new \RuntimeException('No fue posible guardar el archivo PDF del certificado.');
                }

                try {
                    $document = CertificateDocument::query()->create([
                        'uploaded_by' => auth()->id(),
                        'imported_without_management' => $managementId === null,
                        'control_number' => $normalizedControlNumber,
                        'issued_on' => $issuedOn,
                        'file_name' => $downloadName,
                        'original_file_name' => $originalFileName,
                        'file_path' => $path,
                    ]);

                    if ($managementId !== null) {
                        $document->managements()->attach($managementId);
                    }

                    return $document;
                } catch (Throwable $exception) {
                    Storage::disk('local')->delete($path);

                    throw $exception;
                }
            },
        );
    }
}

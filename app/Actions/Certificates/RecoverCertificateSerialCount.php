<?php

namespace App\Actions\Certificates;

use App\Models\CertificateDocument;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RecoverCertificateSerialCount
{
    public function __construct(
        private LoadCertificateDocumentSerialCounts $serialCounts,
        private ImportCertificatesFromPdf $extractor,
    ) {}

    public function handle(CertificateDocument $document): bool
    {
        if ($document->getRawOriginal('total_serial_count') !== null) {
            return true;
        }

        if ($document->total_serial_count === null) {
            $this->serialCounts->handle(collect([$document]));
        }

        $total = $document->total_serial_count;

        if ($total === null) {
            if (! Storage::disk('local')->exists($document->file_path)) {
                return false;
            }

            try {
                $analysis = $this->extractor->parseForComparison(Storage::disk('local')->path($document->file_path));
                $total = count($analysis['records']) + $analysis['invalidCount'];
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        }

        CertificateDocument::query()->whereKey($document->id)
            ->whereNull('total_serial_count')->update(['total_serial_count' => $total]);

        return true;
    }
}

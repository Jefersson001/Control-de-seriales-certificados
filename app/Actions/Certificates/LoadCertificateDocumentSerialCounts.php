<?php

namespace App\Actions\Certificates;

use App\CertificateStatus;
use App\Models\CertificateDocument;
use App\Models\MsCertificado;
use App\Models\VehicleIdentificationRecordCertificateSerial;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LoadCertificateDocumentSerialCounts
{
    /** @param Collection<int, CertificateDocument> $documents */
    public function handle(Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

        $controlNumbers = $documents->pluck('control_number');
        $totals = VehicleIdentificationRecordManagementCertificate::query()
            ->whereIn('control_number', $controlNumbers)
            ->select('control_number')
            ->selectRaw('MAX(valid_occurrence_count + invalid_count) as serial_count')
            ->groupBy('control_number')
            ->pluck('serial_count', 'control_number');

        $processedSerials = VehicleIdentificationRecordCertificateSerial::query()
            ->join('vehicle_identification_record_management_certificates as certificates', 'certificates.id', '=', 'vehicle_identification_record_certificate_serials.certificate_id')
            ->whereIn('certificates.control_number', $controlNumbers)
            ->whereNotNull('imported_at')
            ->select('certificates.control_number', 'serial')
            ->toBase();

        $completeLoadSerials = MsCertificado::query()
            ->whereIn('codigo', $documents->where('imported_without_management', true)->pluck('control_number'))
            ->selectRaw('codigo as control_number, niv as serial')
            ->toBase();

        $processedCounts = DB::query()
            ->fromSub($processedSerials->unionAll($completeLoadSerials), 'processed_serials')
            ->whereNotNull('serial')
            ->whereRaw("TRIM(serial) <> ''")
            ->select('control_number')
            ->selectRaw('COUNT(DISTINCT UPPER(TRIM(serial))) as serial_count')
            ->groupBy('control_number')
            ->pluck('serial_count', 'control_number');

        $rankedSerials = MsCertificado::query()
            ->whereIn('codigo', $controlNumbers)
            ->whereRaw("TRIM(niv) <> ''")
            ->select('codigo', 'status')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY codigo, UPPER(TRIM(niv)) ORDER BY updated_at DESC, id DESC) as serial_position')
            ->toBase();
        $statusCounts = DB::query()->fromSub($rankedSerials, 'serial_statuses')
            ->where('serial_position', 1)
            ->select('codigo')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as dispatched_serial_count', [CertificateStatus::Dispatched->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_dispatch_serial_count', [CertificateStatus::PendingDispatch->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned_serial_count', [CertificateStatus::Returned->value])
            ->groupBy('codigo')
            ->get()->keyBy('codigo');

        $cacheKeys = $documents
            ->filter(fn (CertificateDocument $document): bool => $document->total_serial_count === null && ! $totals->has($document->control_number))
            ->map(fn (CertificateDocument $document): string => 'certificate-document-serial-count:'.hash('sha256', $document->file_path))
            ->values()->all();
        $cachedTotals = $cacheKeys === [] ? [] : Cache::many($cacheKeys);

        foreach ($documents as $document) {
            $total = $document->total_serial_count
                ?? $totals->get($document->control_number)
                ?? ($cachedTotals['certificate-document-serial-count:'.hash('sha256', $document->file_path)] ?? null);
            $document->setAttribute('total_serial_count', $total === null ? null : (int) $total);
            $document->setAttribute('processed_serial_count', (int) $processedCounts->get($document->control_number, 0));
            $statuses = $statusCounts->get($document->control_number);
            $document->setAttribute('dispatched_serial_count', (int) ($statuses->dispatched_serial_count ?? 0));
            $document->setAttribute('pending_dispatch_serial_count', (int) ($statuses->pending_dispatch_serial_count ?? 0));
            $document->setAttribute('returned_serial_count', (int) ($statuses->returned_serial_count ?? 0));
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\NivComplianceCertificate;
use App\UserPermission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NivComplianceCertificateDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(NivComplianceCertificate $certificate): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewNivComplianceCertificates), 403);
        abort_unless(Storage::disk('local')->exists($certificate->file_path), 404);

        return Storage::disk('local')->download($certificate->file_path, $certificate->original_file_name, ['Content-Type' => 'application/pdf']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CertificateDocument;
use App\UserPermission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateDocumentDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CertificateDocument $certificateDocument): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewCertificateDocuments), 403);
        abort_unless(Storage::disk('local')->exists($certificateDocument->file_path), 404);

        return Storage::disk('local')->download(
            $certificateDocument->file_path,
            $certificateDocument->file_name,
            ['Content-Type' => 'application/pdf'],
        );
    }
}

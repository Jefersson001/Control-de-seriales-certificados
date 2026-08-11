<?php

namespace App\Http\Controllers;

use App\Actions\Dispatches\PrintDispatchCertificates;
use App\Models\Dispatch;
use App\UserPermission;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispatchCertificatePrintController extends Controller
{
    public function __invoke(Dispatch $dispatch): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewDispatches), 403);

        try {
            $content = app(PrintDispatchCertificates::class)->handle($dispatch);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $downloadName = 'certificados-'.Str::slug($dispatch->name).'.pdf';

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $downloadName,
            ['Content-Type' => 'application/pdf'],
        );
    }
}

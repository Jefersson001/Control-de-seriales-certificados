<?php

namespace App\Http\Controllers;

use App\Actions\Dispatches\PrintDispatchCertificates;
use App\Models\Dispatch;
use App\Models\SystemSetting;
use App\UserPermission;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispatchCertificatePrintController extends Controller
{
    public function __invoke(Dispatch $dispatch): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewDispatches), 403);

        $originalTimeLimit = (int) ini_get('max_execution_time');

        try {
            set_time_limit(SystemSetting::dispatchCertificateTimeoutSeconds());
            $content = app(PrintDispatchCertificates::class)->handle($dispatch);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        } finally {
            set_time_limit($originalTimeLimit);
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

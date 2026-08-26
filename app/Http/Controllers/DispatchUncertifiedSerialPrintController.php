<?php

namespace App\Http\Controllers;

use App\Actions\Dispatches\PrintDispatchUncertifiedSerials;
use App\Models\Dispatch;
use App\UserPermission;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispatchUncertifiedSerialPrintController extends Controller
{
    public function __invoke(
        Dispatch $dispatch,
        PrintDispatchUncertifiedSerials $printer,
    ): StreamedResponse {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewDispatches), 403);

        try {
            $content = $printer->handle($dispatch);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $downloadName = 'seriales-no-certificados-'.Str::slug($dispatch->name).'.pdf';

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $downloadName,
            ['Content-Type' => 'application/pdf'],
        );
    }
}

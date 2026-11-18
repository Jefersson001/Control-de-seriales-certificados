<?php

namespace App\Actions\Dispatches;

use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeDispatch
{
    public function handle(Dispatch $dispatch): void
    {
        try {
            Cache::lock('dispatch-finalization', 120)->block(5, fn () => DB::transaction(function () use ($dispatch): void {
                $dispatch = Dispatch::query()->lockForUpdate()->findOrFail($dispatch->id);

                if ($dispatch->status === DispatchStatus::Done) {
                    return;
                }

                $ids = $dispatch->lines()->pluck('ms_certificado_id');

                if ($ids->isEmpty() && ! $dispatch->uncertifiedLines()->exists()) {
                    throw new RuntimeException('El despacho debe tener al menos un NIV.');
                }

                $available = MsCertificado::query()
                    ->whereKey($ids)
                    ->whereIn('status', [CertificateStatus::PendingDispatch, CertificateStatus::Returned])
                    ->lockForUpdate()
                    ->count();

                if ($available !== $ids->count()) {
                    throw new RuntimeException('Uno o más NIV ya no están disponibles para despachar. Actualiza el formulario.');
                }

                app(ValidateDispatchSerials::class)->handle($dispatch);

                if ($ids->isNotEmpty()) {
                    MsCertificado::query()->whereKey($ids)->update([
                        'status' => CertificateStatus::Dispatched,
                        'updated_at' => now(),
                    ]);
                }
                $dispatch->update([
                    'status' => DispatchStatus::Done,
                    'dispatch_date' => now()->toDateString(),
                    'finalized_at' => now(),
                ]);
            }));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Hay otro despacho finalizándose. Inténtalo nuevamente en unos segundos.', previous: $exception);
        }
    }
}

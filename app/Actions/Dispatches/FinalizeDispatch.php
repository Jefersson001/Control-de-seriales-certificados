<?php

namespace App\Actions\Dispatches;

use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeDispatch
{
    public function handle(Dispatch $dispatch): void
    {
        DB::transaction(function () use ($dispatch): void {
            $dispatch = Dispatch::query()->lockForUpdate()->findOrFail($dispatch->id);

            if ($dispatch->status === DispatchStatus::Done) {
                return;
            }

            $ids = $dispatch->lines()->pluck('ms_certificado_id');

            if ($ids->isEmpty()) {
                throw new RuntimeException('El despacho debe tener al menos un NIV.');
            }

            $available = MsCertificado::query()
                ->whereKey($ids)
                ->where('status', CertificateStatus::PendingDispatch)
                ->lockForUpdate()
                ->count();

            if ($available !== $ids->count()) {
                throw new RuntimeException('Uno o más NIV ya no están disponibles para despachar. Actualiza el formulario.');
            }

            MsCertificado::query()->whereKey($ids)->update([
                'status' => CertificateStatus::Dispatched,
                'updated_at' => now(),
            ]);
            $dispatch->update([
                'status' => DispatchStatus::Done,
                'dispatch_date' => now()->toDateString(),
                'finalized_at' => now(),
            ]);
        });
    }
}

<?php

namespace App\Actions\Returns;

use App\CertificateStatus;
use App\Models\MsCertificado;
use App\Models\ProductReturn;
use App\ReturnStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeReturn
{
    public function handle(ProductReturn $return): void
    {
        DB::transaction(function () use ($return): void {
            $return = ProductReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($return->status === ReturnStatus::Done) {
                return;
            }

            $ids = $return->lines()->pluck('ms_certificado_id');

            if ($ids->isEmpty()) {
                throw new RuntimeException('La devolución debe tener al menos un NIV.');
            }

            $available = MsCertificado::query()
                ->whereKey($ids)
                ->where('status', CertificateStatus::Dispatched)
                ->lockForUpdate()
                ->count();

            if ($available !== $ids->count()) {
                throw new RuntimeException('Uno o más NIV ya no están disponibles para devolver. Actualiza el formulario.');
            }

            MsCertificado::query()->whereKey($ids)->update([
                'status' => CertificateStatus::Returned,
                'updated_at' => now(),
            ]);
            $return->update([
                'status' => ReturnStatus::Done,
                'return_date' => now()->toDateString(),
                'finalized_at' => now(),
            ]);
        });
    }
}

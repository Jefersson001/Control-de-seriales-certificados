<?php

namespace App\Actions\Dispatches;

use App\Actions\Products\MatchProductSerial;
use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\DispatchUncertifiedLine;
use App\Models\MsCertificado;
use App\Models\ProductReturnLine;
use App\ReturnStatus;
use Illuminate\Support\Str;
use RuntimeException;

class ValidateDispatchSerials
{
    public function handle(Dispatch $dispatch): void
    {
        $certificates = MsCertificado::query()->whereIn('id', $dispatch->lines()->select('ms_certificado_id'))->lockForUpdate()->get();
        $uncertified = $dispatch->uncertifiedLines()->with('product')->lockForUpdate()->get();
        $matcher = app(MatchProductSerial::class);
        $serials = $certificates->pluck('niv')->merge($uncertified->pluck('niv'))->map(fn (string $niv): string => Str::upper(trim($niv)));
        if ($serials->count() !== $serials->unique()->count()) {
            throw new RuntimeException('El mismo NIV no puede aparecer más de una vez en el despacho.');
        }
        foreach ($serials as $niv) {
            if (! $matcher->isValid($niv)) {
                throw new RuntimeException("El NIV {$niv} no tiene 17 caracteres válidos. Corrígelo antes de despachar.");
            }
        }
        foreach ($certificates as $certificate) {
            $declaredBrand = $matcher->normalizeBrand($certificate->marca);
            $manufacturerBrand = $matcher->brand($certificate->niv);
            if ($declaredBrand !== null && $manufacturerBrand !== null && $declaredBrand !== $manufacturerBrand) {
                throw new RuntimeException("La marca del NIV {$certificate->niv} no coincide con su fabricante. Revisa el certificado antes de despachar.");
            }
            foreach (['marca', 'modelo', 'tipo', 'fabricacion', 'codigo'] as $field) {
                if (trim((string) $certificate->{$field}) === '') {
                    throw new RuntimeException("El NIV {$certificate->niv} tiene información incompleta en el maestro. Revisa sus datos antes de despachar.");
                }
            }
            if ($certificate->anio < 1000 || $certificate->anio > 9999) {
                throw new RuntimeException("El NIV {$certificate->niv} tiene un año pendiente de revisión.");
            }
        }
        foreach ($uncertified as $line) {
            if ($line->product !== null && ! $matcher->handle($line->product, $line->niv)) {
                throw new RuntimeException("El producto del NIV {$line->niv} cambió o no coincide. Vuelve a cargar el PDF del despacho.");
            }
        }
        if (MsCertificado::query()->whereIn('niv', $uncertified->pluck('niv'))->exists()) {
            throw new RuntimeException('Uno de los seriales sin certificar ya existe en el maestro. Vuelve a cargar el PDF para actualizar el despacho.');
        }
        if (MsCertificado::query()->whereIn('niv', $serials)->where('status', CertificateStatus::Dispatched)->exists()) {
            throw new RuntimeException('Uno de los NIV ya está despachado. Registra su devolución antes de volver a despacharlo.');
        }
        $previous = DispatchUncertifiedLine::query()->whereIn('niv', $serials)
            ->where('dispatch_id', '!=', $dispatch->id)->whereHas('dispatch', fn ($query) => $query->where('status', DispatchStatus::Done))->pluck('niv');
        $previous = $previous->merge(DispatchLine::query()->with('certificate:id,niv')
            ->where('dispatch_id', '!=', $dispatch->id)->whereHas('dispatch', fn ($query) => $query->where('status', DispatchStatus::Done))
            ->whereHas('certificate', fn ($query) => $query->whereIn('niv', $serials))->get()->pluck('certificate.niv'))->unique();
        $returnedIds = ProductReturnLine::query()->whereIn('ms_certificado_id', $certificates->modelKeys())
            ->whereHas('productReturn', fn ($query) => $query->where('status', ReturnStatus::Done))->pluck('ms_certificado_id');
        $returnedSerials = $certificates->filter(fn (MsCertificado $certificate): bool => $certificate->status === CertificateStatus::Returned && $returnedIds->contains($certificate->id))->pluck('niv');
        foreach ($previous as $niv) {
            if (! $returnedSerials->contains($niv)) {
                throw new RuntimeException("El NIV {$niv} ya pertenece a un despacho finalizado y no tiene una devolución registrada.");
            }
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dispatch_id', 'ms_certificado_id'])]
class DispatchLine extends Model
{
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(MsCertificado::class, 'ms_certificado_id');
    }
}

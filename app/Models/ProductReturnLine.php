<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['return_id', 'ms_certificado_id'])]
class ProductReturnLine extends Model
{
    protected $table = 'return_lines';

    /** @return BelongsTo<ProductReturn, $this> */
    public function productReturn(): BelongsTo
    {
        return $this->belongsTo(ProductReturn::class, 'return_id');
    }

    /** @return BelongsTo<MsCertificado, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(MsCertificado::class, 'ms_certificado_id');
    }
}

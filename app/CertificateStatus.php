<?php

namespace App;

enum CertificateStatus: string
{
    case PendingDispatch = 'pending_dispatch';
    case Dispatched = 'dispatched';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PendingDispatch => 'Por despachar',
            self::Dispatched => 'Despachado',
            self::Returned => 'Devuelto',
        };
    }
}

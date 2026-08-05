<?php

namespace App;

enum MotorcycleSerialRequestStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::InProgress => 'En proceso',
            self::Done => 'Hecho',
        };
    }
}

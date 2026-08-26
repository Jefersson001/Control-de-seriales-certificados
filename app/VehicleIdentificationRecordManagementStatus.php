<?php

namespace App;

enum VehicleIdentificationRecordManagementStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::InProgress => 'Parcialmente en proceso',
            self::Done => 'Hecho',
        };
    }
}

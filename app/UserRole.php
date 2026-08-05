<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::User => 'Usuario',
        };
    }
}

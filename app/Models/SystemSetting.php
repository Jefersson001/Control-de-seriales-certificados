<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const LIVEWIRE_PAYLOAD_MAX_MB = 'livewire_payload_max_mb';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public static function livewirePayloadMaxMb(): int
    {
        return max(1, (int) static::query()
            ->where('key', self::LIVEWIRE_PAYLOAD_MAX_MB)
            ->value('value'));
    }
}

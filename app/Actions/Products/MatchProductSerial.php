<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Support\Str;

class MatchProductSerial
{
    public function normalizeBrand(string $brand): ?string
    {
        return match (preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($brand)))) {
            'BERA' => 'bera',
            'ZONTES' => 'zontes',
            'MORINI', 'MOTOMORINI' => 'morini',
            default => null,
        };
    }

    public function isValid(string $serial): bool
    {
        $serial = Str::upper(trim($serial));

        return preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $serial) === 1 && preg_match('/[A-Z]/', substr($serial, 0, 3)) === 1;
    }

    public function category(string $serial, string $brand = ''): string
    {
        $detected = $this->brand($serial);

        return in_array($detected, ['zontes', 'morini'], true) || in_array(Str::lower(Str::ascii(trim($brand))), ['zontes', 'morini', 'moto morini'], true) ? 'importados' : 'nacional';
    }

    public function code(string $serial, string $brand): ?string
    {
        $serial = Str::upper(trim($serial));
        if (! $this->isValid($serial)) {
            return null;
        }

        return match ($brand) {
            'bera' => substr($serial, 3, 2),
            'zontes', 'morini' => substr($serial, 0, 8),
            default => null,
        };
    }

    public function brand(string $serial): ?string
    {
        return match (Str::upper(substr(trim($serial), 0, 3))) {
            '8YZ' => 'bera',
            'LD3' => 'zontes',
            'HL4' => 'morini',
            default => null,
        };
    }

    public function handle(Product $product, string $serial): bool
    {
        $brand = $this->brand($serial);
        if ($brand !== null && $brand !== $product->brand) {
            return false;
        }
        $code = $this->code($serial, $product->brand);
        $productCode = Str::upper(trim((string) $product->niv));
        if ($productCode === '') {
            $productCode = Str::upper(trim((string) $product->first_value).trim((string) $product->second_value));
        }

        return $code !== null && $productCode === $code;
    }
}

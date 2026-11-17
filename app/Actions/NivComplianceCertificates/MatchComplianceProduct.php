<?php

namespace App\Actions\NivComplianceCertificates;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class MatchComplianceProduct
{
    /**
     * @param  array<string, mixed>  $fields
     * @return Collection<int, Product>
     */
    public function handle(string $category, array $fields): Collection
    {
        $brand = $this->brand($fields);
        if ($brand === null || ! in_array($category, ['nacional', 'importados'], true)) {
            return new Collection;
        }

        return Product::query()->where('category', $category)->where('brand', $brand)
            ->get(['id', 'name', 'category', 'brand', 'niv', 'first_value', 'second_value'])
            ->filter(fn (Product $product): bool => $this->matches($product, $category, $fields))->values();
    }

    /** @param array<string, mixed> $fields */
    public function matches(Product $product, string $category, array $fields): bool
    {
        $brand = $this->brand($fields);
        if ($brand === null || $product->category !== $category || $product->brand !== $brand) {
            return false;
        }

        if (in_array($brand, ['bera', 'zontes'], true)) {
            $code = $this->code($fields);
            $productCode = Str::upper(trim((string) $product->niv));
            if ($productCode === '' && $brand === 'zontes') {
                $productCode = Str::upper(trim((string) $product->first_value).trim((string) $product->second_value));
            }

            return $code !== null && $productCode === $code;
        }

        $name = $this->descriptionKey($product->name);

        return $name !== '' && in_array($name, [
            $this->descriptionKey($fields['model'] ?? ''),
            $this->descriptionKey($fields['series_version'] ?? ''),
        ], true);
    }

    /** @param array<string, mixed> $fields */
    public function brand(array $fields): ?string
    {
        return match ($this->descriptionKey($fields['brand'] ?? '')) {
            'BERA' => 'bera',
            'ZONTES' => 'zontes',
            'MORINI', 'MOTOMORINI' => 'morini',
            default => null,
        };
    }

    /** @param array<string, mixed> $fields */
    public function code(array $fields): ?string
    {
        $serial = is_string($fields['serial_pattern'] ?? null) ? Str::upper(trim($fields['serial_pattern'])) : '';
        if (! preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $serial)) {
            return null;
        }

        return match ($this->brand($fields)) {
            'zontes' => substr($serial, 0, 8),
            'bera' => substr($serial, 3, 2),
            default => null,
        };
    }

    private function descriptionKey(mixed $value): string
    {
        return is_string($value) ? (string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($value))) : '';
    }
}

<?php

namespace App\Actions\NivComplianceCertificates;

use App\Actions\Products\MatchProductSerial;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

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

        return app(MatchProductSerial::class)->handle($product, is_string($fields['serial_pattern'] ?? null) ? $fields['serial_pattern'] : '');
    }

    /** @param array<string, mixed> $fields */
    public function brand(array $fields): ?string
    {
        return app(MatchProductSerial::class)->normalizeBrand(is_string($fields['brand'] ?? null) ? $fields['brand'] : '');
    }

    /** @param array<string, mixed> $fields */
    public function code(array $fields): ?string
    {
        return app(MatchProductSerial::class)->code(is_string($fields['serial_pattern'] ?? null) ? $fields['serial_pattern'] : '', $this->brand($fields) ?? '');
    }
}

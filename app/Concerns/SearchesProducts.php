<?php

namespace App\Concerns;

use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Product search and stock lookups shared by the inventory screens.
 */
trait SearchesProducts
{
    /**
     * Active products matching a name, internal code or barcode.
     *
     * @return Collection<int, Product>
     */
    protected function searchProducts(string $term, int $limit = 8): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Stock of the given products in a warehouse, by product id.
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    protected function stockIn(?Warehouse $warehouse, array $productIds): array
    {
        if ($warehouse === null || $productIds === []) {
            return [];
        }

        return Stock::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as available')
            ->pluck('available', 'product_id')
            ->map(fn ($available) => (string) $available)
            ->all();
    }
}

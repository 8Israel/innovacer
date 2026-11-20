<?php

namespace App\Services;

use App\Models\InventoryOperation;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * Moves stock and writes the kardex. Every method must run inside the caller's
 * transaction: stock rows are locked with lockForUpdate() until it commits.
 */
class InventoryService
{
    private const SCALE = 3;

    /**
     * Take a quantity of a product out of a warehouse, oldest lots first (FIFO),
     * writing one kardex line per lot touched.
     *
     * @throws ValidationException when the warehouse does not hold enough stock.
     */
    public function removeFifo(
        InventoryOperation $operation,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        ?SaleItem $saleItem = null,
    ): void {
        $stocks = Stock::query()
            ->select('stocks.*')
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->where('stocks.product_id', $product->id)
            ->where('stocks.warehouse_id', $warehouse->id)
            ->where('stocks.quantity', '>', 0)
            ->orderBy('lots.received_at')
            ->orderBy('lots.id')
            ->lockForUpdate()
            ->get();

        $available = $stocks->reduce(fn (string $carry, Stock $stock) => bcadd($carry, $stock->quantity, self::SCALE), '0');

        if (bccomp($available, $quantity, self::SCALE) < 0) {
            throw ValidationException::withMessages([
                'items' => __('No hay suficiente existencia de :product en :warehouse: hay :available y se necesitan :quantity.', [
                    'product' => $product->name,
                    'warehouse' => $warehouse->name,
                    'available' => $this->format($available),
                    'quantity' => $this->format($quantity),
                ]),
            ]);
        }

        $remaining = $quantity;
        $stockBefore = $available;

        foreach ($stocks as $stock) {
            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                break;
            }

            $taken = bccomp($stock->quantity, $remaining, self::SCALE) < 0 ? $stock->quantity : $remaining;
            $stockAfter = bcsub($stockBefore, $taken, self::SCALE);

            $stock->update(['quantity' => bcsub($stock->quantity, $taken, self::SCALE)]);

            $operation->movements()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'lot_id' => $stock->lot_id,
                'sale_item_id' => $saleItem?->id,
                'quantity' => bcsub('0', $taken, self::SCALE),
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
            ]);

            $remaining = bcsub($remaining, $taken, self::SCALE);
            $stockBefore = $stockAfter;
        }
    }

    /**
     * Drop insignificant trailing zeros for messages: "3.000" becomes "3".
     */
    private function format(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }
}

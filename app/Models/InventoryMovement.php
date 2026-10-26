<?php

namespace App\Models;

use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One kardex line. Stock before/after are the product's totals in the
 * warehouse, so they can be checked against a physical count.
 *
 * @property int $id
 * @property int $inventory_operation_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int $lot_id
 * @property int|null $sale_item_id
 * @property string $quantity Positive adds stock, negative removes it.
 * @property string $stock_before
 * @property string $stock_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['inventory_operation_id', 'product_id', 'warehouse_id', 'lot_id', 'sale_item_id', 'quantity', 'stock_before', 'stock_after'])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
        ];
    }

    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}

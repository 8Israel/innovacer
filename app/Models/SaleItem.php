<?php

namespace App\Models;

use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sale_id
 * @property int $product_id
 * @property string $quantity
 * @property PriceType $price_type
 * @property string $unit_price
 * @property string $discount_rate
 * @property string $discount_amount
 * @property string $subtotal
 * @property string $iva_rate
 * @property string $iva_amount
 * @property string $ieps_rate
 * @property string $ieps_amount
 * @property string $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['sale_id', 'product_id', 'quantity', 'price_type', 'unit_price', 'discount_rate', 'discount_amount', 'subtotal', 'iva_rate', 'iva_amount', 'ieps_rate', 'ieps_amount', 'total'])]
class SaleItem extends Model
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'price_type' => PriceType::class,
            'unit_price' => 'decimal:2',
            'discount_rate' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'iva_rate' => 'decimal:4',
            'iva_amount' => 'decimal:2',
            'ieps_rate' => 'decimal:4',
            'ieps_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function customerReturnItems(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class);
    }
}

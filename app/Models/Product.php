<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $brand
 * @property string|null $presentation
 * @property int|null $category_id
 * @property string $unit
 * @property string|null $description
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $reference_cost
 * @property string|null $unit_price
 * @property string|null $wholesale_price
 * @property string $tax_rate
 * @property string|null $ieps_rate
 * @property string|null $product_key
 * @property string|null $product_key_name
 * @property string|null $unit_key
 * @property string|null $unit_name
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'brand', 'presentation', 'category_id', 'unit', 'description', 'sku', 'barcode', 'reference_cost', 'unit_price', 'wholesale_price', 'tax_rate', 'ieps_rate', 'product_key', 'product_key_name', 'unit_key', 'unit_name', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reference_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'ieps_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Products that already have a price and can therefore be invoiced.
     */
    #[Scope]
    protected function priced(Builder $query): Builder
    {
        return $query->whereNotNull('unit_price');
    }
}

<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $unit_price
 * @property string $tax_rate
 * @property string $product_key
 * @property string|null $product_key_name
 * @property string $unit_key
 * @property string $unit_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'sku', 'barcode', 'unit_price', 'tax_rate', 'product_key', 'product_key_name', 'unit_key', 'unit_name'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:4',
        ];
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

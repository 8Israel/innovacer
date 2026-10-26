<?php

namespace App\Models;

use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One receipt of a product. Lots are internal bookkeeping only: they are
 * consumed first-in first-out and never separated physically.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $supplier_id
 * @property int|null $owner_supplier_id Null when the business owns the lot.
 * @property int $inventory_operation_id
 * @property string|null $unit_cost
 * @property string $quantity_received
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['product_id', 'supplier_id', 'owner_supplier_id', 'inventory_operation_id', 'unit_cost', 'quantity_received', 'received_at'])]
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'quantity_received' => 'decimal:3',
            'received_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function ownerSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'owner_supplier_id');
    }

    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}

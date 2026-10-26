<?php

namespace App\Models;

use Database\Factories\CustomerReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer return; puts the product back in stock and issues a refund note.
 *
 * @property int $id
 * @property int|null $cash_session_id
 * @property string $folio
 * @property int $sale_id
 * @property int|null $customer_id
 * @property int $warehouse_id
 * @property int $user_id
 * @property int|null $inventory_operation_id
 * @property RefundMethod $refund_method
 * @property string $refund_amount
 * @property string|null $reference
 * @property string|null $reason
 * @property Carbon $returned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'folio', 'sale_id', 'customer_id', 'warehouse_id', 'user_id', 'inventory_operation_id', 'refund_method', 'refund_amount', 'reference', 'reason', 'returned_at'])]
class CustomerReturn extends Model
{
    /** @use HasFactory<CustomerReturnFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'refund_method' => RefundMethod::class,
            'refund_amount' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sale. Whatever is not covered by its payments is owed on credit.
 * A pending sale has no folio, sale date, payments or inventory operation.
 *
 * @property int $id
 * @property int|null $cash_session_id
 * @property string|null $folio
 * @property int|null $customer_id
 * @property int|null $customer_destination_id
 * @property int $warehouse_id
 * @property int $user_id Who charged the sale.
 * @property int|null $authorized_by_id Who approved its extra discount or credit over the limit.
 * @property string|null $authorization_reason
 * @property int|null $inventory_operation_id
 * @property SaleStatus $status
 * @property string $discount_rate
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $ieps_total
 * @property string $total
 * @property string|null $notes
 * @property Carbon|null $sold_at
 * @property Carbon|null $held_at
 * @property string|null $hold_note
 * @property Carbon|null $canceled_at
 * @property int|null $canceled_by_id The owner who approved the cancellation.
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'folio', 'customer_id', 'customer_destination_id', 'warehouse_id', 'user_id', 'authorized_by_id', 'authorization_reason', 'inventory_operation_id', 'status', 'discount_rate', 'subtotal', 'discount_total', 'tax_total', 'ieps_total', 'total', 'notes', 'sold_at', 'held_at', 'hold_note', 'canceled_at', 'canceled_by_id', 'cancel_reason'])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'discount_rate' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'ieps_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sold_at' => 'datetime',
            'held_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === SaleStatus::Pending;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerDestination(): BelongsTo
    {
        return $this->belongsTo(CustomerDestination::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_id');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by_id');
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
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * Parts of customer payments (abonos) applied to this sale.
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    public function customerReturns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withTimestamps();
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}

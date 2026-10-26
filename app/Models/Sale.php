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
 *
 * @property int $id
 * @property int|null $cash_session_id
 * @property string $folio
 * @property int|null $customer_id
 * @property int|null $customer_destination_id
 * @property int $warehouse_id
 * @property int $user_id
 * @property int|null $inventory_operation_id
 * @property SaleStatus $status
 * @property string $discount_rate
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $ieps_total
 * @property string $total
 * @property string|null $notes
 * @property Carbon $sold_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'folio', 'customer_id', 'customer_destination_id', 'warehouse_id', 'user_id', 'inventory_operation_id', 'status', 'discount_rate', 'subtotal', 'discount_total', 'tax_total', 'ieps_total', 'total', 'notes', 'sold_at', 'canceled_at'])]
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
            'canceled_at' => 'datetime',
        ];
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

    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
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

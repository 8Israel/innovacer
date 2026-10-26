<?php

namespace App\Models;

use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shift of the single cash register, from opening to closing.
 * open_lock is 1 while open and NULL once closed; its unique index
 * keeps the database from ever holding two open sessions.
 *
 * @property int $id
 * @property int $opened_by_id
 * @property Carbon $opened_at
 * @property string $opening_cash
 * @property string|null $opening_note
 * @property int|null $closed_by_id
 * @property Carbon|null $closed_at
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $cash_difference
 * @property string|null $cash_left Cash left in the register for the next session.
 * @property string $card_total
 * @property string $transfer_total
 * @property string $credit_total
 * @property string|null $closing_note
 * @property CashSessionStatus $status
 * @property int|null $open_lock
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'opened_by_id', 'opened_at', 'opening_cash', 'opening_note', 'closed_by_id', 'closed_at', 'expected_cash', 'counted_cash',
    'cash_difference', 'cash_left', 'card_total', 'transfer_total', 'credit_total', 'closing_note', 'status', 'open_lock',
])]
class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'closed_at' => 'datetime',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'cash_left' => 'decimal:2',
            'card_total' => 'decimal:2',
            'transfer_total' => 'decimal:2',
            'credit_total' => 'decimal:2',
            'status' => CashSessionStatus::class,
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function salePayments(): HasMany
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

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}

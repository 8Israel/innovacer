<?php

namespace App\Models;

use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A money payment to a supplier; its allocations spread it across lots.
 *
 * @property int $id
 * @property int|null $cash_session_id
 * @property int $supplier_id
 * @property int $user_id
 * @property PaymentMethod $method
 * @property string $amount
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'supplier_id', 'user_id', 'method', 'amount', 'reference', 'note', 'paid_at'])]
class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}

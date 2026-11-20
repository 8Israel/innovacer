<?php

namespace App\Models;

use Database\Factories\CustomerPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payment (abono) toward a customer's credit balance for one of their destinations,
 * spread over that destination's sales through its allocations.
 *
 * @property int $id
 * @property int|null $cash_session_id
 * @property int $customer_id
 * @property int|null $customer_destination_id
 * @property int $user_id
 * @property PaymentMethod $method
 * @property string $amount
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'customer_id', 'customer_destination_id', 'user_id', 'method', 'amount', 'reference', 'note', 'paid_at'])]
class CustomerPayment extends Model
{
    /** @use HasFactory<CustomerPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
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

    /**
     * How the payment was spread over the customer's sales.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}

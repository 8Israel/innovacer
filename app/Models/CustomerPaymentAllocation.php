<?php

namespace App\Models;

use Database\Factories\CustomerPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The part of a customer payment (abono) applied to one sale.
 *
 * @property int $id
 * @property int $customer_payment_id
 * @property int $sale_id
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['customer_payment_id', 'sale_id', 'amount'])]
class CustomerPaymentAllocation extends Model
{
    /** @use HasFactory<CustomerPaymentAllocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}

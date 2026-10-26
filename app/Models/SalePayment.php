<?php

namespace App\Models;

use Database\Factories\SalePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $cash_session_id
 * @property int $sale_id
 * @property PaymentMethod $method
 * @property string $amount
 * @property string|null $received_amount
 * @property string|null $change_amount
 * @property string|null $reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'sale_id', 'method', 'amount', 'received_amount', 'change_amount', 'reference'])]
class SalePayment extends Model
{
    /** @use HasFactory<SalePaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}

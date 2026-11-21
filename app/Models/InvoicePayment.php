<?php

namespace App\Models;

use Database\Factories\InvoicePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One installment of a PPD invoice paid by a customer payment, covered by a
 * payment complement (CFDI of type P).
 *
 * @property int $id
 * @property int $invoice_id The payment complement.
 * @property int $related_invoice_id The PPD invoice being paid.
 * @property int $customer_payment_id
 * @property int $installment Consecutive per related invoice, starting at 1.
 * @property string $previous_balance
 * @property string $amount_paid
 * @property string $outstanding_balance previous_balance − amount_paid.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['invoice_id', 'related_invoice_id', 'customer_payment_id', 'installment', 'previous_balance', 'amount_paid', 'outstanding_balance'])]
class InvoicePayment extends Model
{
    /** @use HasFactory<InvoicePaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'installment' => 'integer',
            'previous_balance' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }
}

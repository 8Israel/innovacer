<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property InvoiceStatus $status
 * @property InvoiceType $type
 * @property string|null $facturapi_id
 * @property string|null $uuid Folio fiscal assigned by the SAT when stamped.
 * @property string|null $series
 * @property string|null $folio
 * @property string $payment_form
 * @property string $payment_method
 * @property string $cfdi_use
 * @property string $currency
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string|null $pdf_url
 * @property string|null $xml_url
 * @property string|null $error_message
 * @property Carbon|null $stamped_at
 * @property Carbon|null $canceled_at
 * @property string|null $cancellation_reason SAT cancellation motive, 01 to 04.
 * @property string|null $substitution_uuid Folio fiscal of the invoice that replaces it (motive 01).
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['customer_id', 'payment_form', 'payment_method', 'cfdi_use', 'currency'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'type' => InvoiceType::class,
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'stamped_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Sales covered by this invoice.
     */
    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class)->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Installments paid on this PPD invoice, each covered by a payment complement.
     */
    public function paidInstallments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class, 'related_invoice_id');
    }

    /**
     * What this payment complement covers.
     */
    public function complementPayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function isPaidInInstallments(): bool
    {
        return $this->type === InvoiceType::Income && $this->payment_method === 'PPD';
    }
}

<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property InvoiceStatus $status
 * @property string|null $facturapi_id
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}

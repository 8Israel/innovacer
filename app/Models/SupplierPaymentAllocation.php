<?php

namespace App\Models;

use Database\Factories\SupplierPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $supplier_payment_id
 * @property int $lot_id
 * @property string $quantity
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['supplier_payment_id', 'lot_id', 'quantity', 'amount'])]
class SupplierPaymentAllocation extends Model
{
    /** @use HasFactory<SupplierPaymentAllocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'amount' => 'decimal:2',
        ];
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\LoanEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ledger line of an in-kind debt: positive when product is taken from a
 * lender (a loan), negative when it is repaid. A repayment points to the
 * loan it repays; the debt is the sum per lender and product.
 *
 * @property int $id
 * @property int $lender_id
 * @property int $product_id
 * @property int $inventory_operation_id
 * @property int|null $repays_loan_entry_id
 * @property string $quantity
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['lender_id', 'product_id', 'inventory_operation_id', 'repays_loan_entry_id', 'quantity', 'note'])]
class LoanEntry extends Model
{
    /** @use HasFactory<LoanEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'lender_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    /**
     * The loan this entry repays (only on repayments).
     */
    public function repaidLoan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repays_loan_entry_id');
    }

    /**
     * Repayments of this loan; their quantities are negative.
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(self::class, 'repays_loan_entry_id');
    }
}

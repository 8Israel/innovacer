<?php

namespace App\Models;

use Database\Factories\InventoryOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Header of an immutable inventory operation; its movements form the kardex.
 * Mistakes are corrected with a reversal operation, never by deleting.
 *
 * @property int $id
 * @property InventoryOperationType $type
 * @property int $user_id
 * @property int|null $supplier_id
 * @property string|null $responsible
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $reference
 * @property int|null $reversal_of_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'user_id', 'supplier_id', 'responsible', 'reason', 'note', 'reference', 'reversal_of_id', 'occurred_at'])]
class InventoryOperation extends Model
{
    /** @use HasFactory<InventoryOperationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => InventoryOperationType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function loanEntries(): HasMany
    {
        return $this->hasMany(LoanEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}

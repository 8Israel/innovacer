<?php

namespace App\Models;

use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cash that enters or leaves the register outside of sales and customer payments.
 *
 * @property int $id
 * @property int|null $cash_session_id
 * @property int $user_id
 * @property CashMovementType $type
 * @property string $concept
 * @property string|null $beneficiary
 * @property string $amount
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cash_session_id', 'user_id', 'type', 'concept', 'beneficiary', 'amount', 'reference', 'note', 'occurred_at'])]
class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
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

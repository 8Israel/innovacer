<?php

namespace App\Models;

use Database\Factories\PhysicalCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A count of everything in a warehouse, saved as it goes and applied at the end
 * as a single adjustment (docs/diseno/ConteoFisico.html).
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $started_by_id
 * @property Carbon $started_at
 * @property PhysicalCountStatus $status
 * @property int|null $applied_by_id
 * @property Carbon|null $applied_at
 * @property int|null $inventory_operation_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['warehouse_id', 'started_by_id', 'started_at', 'status', 'applied_by_id', 'applied_at', 'inventory_operation_id', 'note'])]
class PhysicalCount extends Model
{
    /** @use HasFactory<PhysicalCountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'status' => PhysicalCountStatus::class,
            'applied_at' => 'datetime',
        ];
    }

    public function isInProgress(): bool
    {
        return $this->status === PhysicalCountStatus::InProgress;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PhysicalCountItem::class);
    }
}

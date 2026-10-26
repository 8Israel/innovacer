<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A storage location. When it has a lender, everything stored in it belongs
 * to that lender, and taking product out of it creates an in-kind debt.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int|null $lender_id
 * @property bool $is_sellable
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code', 'lender_id', 'is_sellable', 'is_active'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'lender_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function isLoanWarehouse(): bool
    {
        return $this->lender_id !== null;
    }
}

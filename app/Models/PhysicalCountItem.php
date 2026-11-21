<?php

namespace App\Models;

use Database\Factories\PhysicalCountItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $physical_count_id
 * @property int $product_id
 * @property string $system_quantity What the system had when the count started.
 * @property string|null $counted_quantity Null until counted; uncounted products are not adjusted.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['physical_count_id', 'product_id', 'system_quantity', 'counted_quantity'])]
class PhysicalCountItem extends Model
{
    /** @use HasFactory<PhysicalCountItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
        ];
    }

    public function physicalCount(): BelongsTo
    {
        return $this->belongsTo(PhysicalCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

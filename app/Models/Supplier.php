<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property SupplierSettlementType $settlement_type
 * @property string|null $rfc
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'settlement_type', 'rfc', 'phone', 'email', 'notes', 'is_active'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settlement_type' => SupplierSettlementType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Warehouses holding product this supplier lends in kind.
     */
    public function lentWarehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'lender_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    /**
     * Lots still owned by this supplier (consignment).
     */
    public function ownedLots(): HasMany
    {
        return $this->hasMany(Lot::class, 'owner_supplier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function loanEntries(): HasMany
    {
        return $this->hasMany(LoanEntry::class, 'lender_id');
    }
}

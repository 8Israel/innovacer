<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $rfc
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $tax_system
 * @property string|null $cfdi_use
 * @property string|null $zip_code
 * @property string|null $credit_limit
 * @property string $discount_rate
 * @property bool $is_active
 * @property string|null $facturapi_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'rfc', 'email', 'phone', 'address', 'tax_system', 'cfdi_use', 'zip_code', 'credit_limit', 'discount_rate', 'is_active'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'discount_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(CustomerDestination::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}

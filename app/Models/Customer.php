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
 * @property string $rfc
 * @property string|null $email
 * @property string $tax_system
 * @property string $cfdi_use
 * @property string $zip_code
 * @property string|null $facturapi_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'rfc', 'email', 'tax_system', 'cfdi_use', 'zip_code'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}

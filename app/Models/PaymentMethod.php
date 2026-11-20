<?php

namespace App\Models;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Efectivo'),
            self::Card => __('Tarjeta'),
            self::Transfer => __('Transferencia'),
        };
    }
}

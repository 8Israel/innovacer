<?php

namespace App\Models;

enum InventoryOperationType: string
{
    case Entry = 'entry';
    case Transfer = 'transfer';
    case Exit = 'exit';
    case Adjustment = 'adjustment';
    case Sale = 'sale';
    case Return = 'return';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Entry => __('Entrada'),
            self::Transfer => __('Traspaso'),
            self::Exit => __('Salida'),
            self::Adjustment => __('Ajuste por conteo'),
            self::Sale => __('Venta'),
            self::Return => __('Devolución'),
            self::Reversal => __('Cancelación'),
        };
    }
}

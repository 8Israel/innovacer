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
}

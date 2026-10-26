<?php

namespace App\Models;

enum CashMovementType: string
{
    case Inflow = 'inflow';
    case Outflow = 'outflow';
}

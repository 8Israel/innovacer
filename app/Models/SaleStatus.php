<?php

namespace App\Models;

enum SaleStatus: string
{
    case Completed = 'completed';
    case Canceled = 'canceled';
}

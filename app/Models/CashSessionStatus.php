<?php

namespace App\Models;

enum CashSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

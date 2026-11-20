<?php

namespace App\Models;

enum SaleStatus: string
{
    /** Held to be resumed later; it has not touched stock or cash. */
    case Pending = 'pending';
    case Completed = 'completed';
    case Canceled = 'canceled';
}

<?php

namespace App\Models;

enum PhysicalCountStatus: string
{
    case InProgress = 'in_progress';
    case Applied = 'applied';
    case Canceled = 'canceled';
}

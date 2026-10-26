<?php

namespace App\Models;

enum PriceType: string
{
    case Public = 'public';
    case Wholesale = 'wholesale';
}

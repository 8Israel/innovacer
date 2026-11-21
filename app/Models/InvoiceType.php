<?php

namespace App\Models;

/**
 * Kind of CFDI: an income invoice (I) or a payment complement (P).
 */
enum InvoiceType: string
{
    case Income = 'income';
    case Payment = 'payment';
}

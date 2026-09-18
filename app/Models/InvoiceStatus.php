<?php

namespace App\Models;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Stamped = 'stamped';
    case Canceled = 'canceled';
    case Failed = 'failed';
}

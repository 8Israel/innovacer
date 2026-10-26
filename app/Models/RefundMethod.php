<?php

namespace App\Models;

enum RefundMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';

    /** Credited against the customer's balance instead of paid back. */
    case AccountCredit = 'account_credit';
}

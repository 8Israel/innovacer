<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\RefundMethod;
use App\Models\SalePayment;
use App\Models\SaleStatus;

/**
 * What customers owe on credit.
 */
class CustomerBalanceService
{
    use RoundsDecimals;

    private const MONEY = 2;

    /**
     * The customer's balance: what their completed sales left unpaid, minus their
     * payments (abonos) and the returns credited to their account.
     */
    public function balance(Customer $customer): string
    {
        $completedSales = $customer->sales()->where('status', SaleStatus::Completed);

        $charged = $this->decimal((clone $completedSales)->sum('total'), self::MONEY);
        $paidAtSale = $this->decimal(
            SalePayment::query()->whereIn('sale_id', (clone $completedSales)->select('id'))->sum('amount'),
            self::MONEY,
        );
        $customerPayments = $this->decimal($customer->payments()->sum('amount'), self::MONEY);
        $creditedReturns = $this->decimal(
            CustomerReturn::query()
                ->where('customer_id', $customer->id)
                ->where('refund_method', RefundMethod::AccountCredit)
                ->sum('refund_amount'),
            self::MONEY,
        );

        return bcsub(
            bcsub(bcsub($charged, $paidAtSale, self::MONEY), $customerPayments, self::MONEY),
            $creditedReturns,
            self::MONEY,
        );
    }
}

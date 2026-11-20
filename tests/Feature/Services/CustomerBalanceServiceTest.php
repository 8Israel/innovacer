<?php

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleStatus;
use App\Services\CustomerBalanceService;

test('it owes what completed sales left unpaid minus payments and returns credited to the account', function () {
    $customer = Customer::factory()->withCredit()->create();
    $unpaidSale = Sale::factory()->for($customer)->create(['total' => 3200]);
    $partlyPaidSale = Sale::factory()->for($customer)->create(['total' => 1000]);
    SalePayment::factory()->for($partlyPaidSale)->create(['amount' => 400]);
    Sale::factory()->for($customer)->create(['total' => 500, 'status' => SaleStatus::Canceled]);
    Sale::factory()->pending()->for($customer)->create(['total' => 800]);
    Sale::factory()->create(['total' => 9999]);
    CustomerPayment::factory()->for($customer)->create(['amount' => 700]);
    CustomerReturn::factory()->for($unpaidSale)->create(['customer_id' => $customer->id, 'refund_method' => RefundMethod::AccountCredit, 'refund_amount' => 166.50]);
    CustomerReturn::factory()->for($partlyPaidSale)->create(['customer_id' => $customer->id, 'refund_method' => RefundMethod::Cash, 'refund_amount' => 100]);

    $balance = app(CustomerBalanceService::class)->balance($customer);

    expect($balance)->toBe('2933.50');
});

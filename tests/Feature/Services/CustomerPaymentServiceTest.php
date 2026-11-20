<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\CustomerPaymentService;

/**
 * Francisco Castañón from the design: Reynosa owes $2,000 on #0981 and $6,200 on #1012,
 * Jalincher owes $4,200 on #0995.
 *
 * @return array{customer: Customer, reynosa: CustomerDestination, jalincher: CustomerDestination, sale0981: Sale, sale1012: Sale, sale0995: Sale}
 */
function castanonAccounts(): array
{
    $customer = Customer::factory()->withCredit()->create(['name' => 'Francisco Castañón']);
    $reynosa = CustomerDestination::factory()->for($customer)->create(['name' => 'Reynosa']);
    $jalincher = CustomerDestination::factory()->for($customer)->create(['name' => 'Jalincher']);

    $sale0981 = Sale::factory()->for($customer)->for($reynosa)->create(['folio' => 'V-000981', 'total' => 18000, 'sold_at' => '2026-08-25']);
    SalePayment::factory()->for($sale0981)->create(['amount' => 16000]);
    $sale1012 = Sale::factory()->for($customer)->for($reynosa)->create(['folio' => 'V-001012', 'total' => 6200, 'sold_at' => '2026-09-21']);
    $sale0995 = Sale::factory()->for($customer)->for($jalincher)->create(['folio' => 'V-000995', 'total' => 7000, 'sold_at' => '2026-09-03']);
    SalePayment::factory()->for($sale0995)->create(['amount' => 2800]);

    return compact('customer', 'reynosa', 'jalincher', 'sale0981', 'sale1012', 'sale0995');
}

test('it settles the oldest sale of the account first and applies the rest to the next one', function () {
    $session = CashSession::factory()->create();
    ['customer' => $customer, 'reynosa' => $reynosa, 'jalincher' => $jalincher, 'sale0981' => $sale0981, 'sale1012' => $sale1012] = castanonAccounts();
    $balances = app(CustomerBalanceService::class);

    $payment = app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, $reynosa, PaymentMethod::Transfer, '5000', 'ref. 4471');

    expect($payment->cash_session_id)->toBe($session->id);
    expect($payment->allocations()->orderBy('id')->get(['sale_id', 'amount'])->toArray())->toBe([
        ['sale_id' => $sale0981->id, 'amount' => '2000.00'],
        ['sale_id' => $sale1012->id, 'amount' => '3000.00'],
    ]);
    expect($balances->pending($sale0981))->toBe('0.00');
    expect($balances->pending($sale1012))->toBe('3200.00');
    expect($balances->destinationBalance($reynosa))->toBe('3200.00');
    expect($balances->destinationBalance($jalincher))->toBe('4200.00');
    expect($balances->balance($customer))->toBe('7400.00');
});

test('it starts with the sale chosen to apply the payment to', function () {
    CashSession::factory()->create();
    ['customer' => $customer, 'reynosa' => $reynosa, 'sale0981' => $sale0981, 'sale1012' => $sale1012] = castanonAccounts();
    $balances = app(CustomerBalanceService::class);

    app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, $reynosa, PaymentMethod::Cash, '5000', startAt: $sale1012);

    expect($balances->pending($sale1012))->toBe('1200.00');
    expect($balances->pending($sale0981))->toBe('2000.00');
});

test('it applies payments of a customer without accounts to the sales without destination', function () {
    CashSession::factory()->create();
    $customer = Customer::factory()->withCredit()->create();
    $sale = Sale::factory()->for($customer)->create(['total' => 1500]);

    app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, null, PaymentMethod::Cash, '1500');

    expect(app(CustomerBalanceService::class)->pending($sale))->toBe('0.00');
});

test('it refuses a payment without an open register', function () {
    CashSession::factory()->closed()->create();
    ['customer' => $customer, 'reynosa' => $reynosa] = castanonAccounts();

    expect(fn () => app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, $reynosa, PaymentMethod::Cash, '1000'))
        ->toThrow(failsOn('cash_session'));
    expect(CustomerPayment::count())->toBe(0);
});

test('it refuses a payment larger than what the account owes', function () {
    CashSession::factory()->create();
    ['customer' => $customer, 'reynosa' => $reynosa] = castanonAccounts();

    expect(fn () => app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, $reynosa, PaymentMethod::Cash, '8200.01'))
        ->toThrow(failsOn('amount'));
    expect(CustomerPayment::count())->toBe(0);
});

test('it requires an account of the customer when the customer has accounts', function (string $case) {
    CashSession::factory()->create();
    ['customer' => $customer] = castanonAccounts();
    $destination = $case === 'another customer' ? CustomerDestination::factory()->create() : null;

    expect(fn () => app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, $destination, PaymentMethod::Cash, '100'))
        ->toThrow(failsOn('customer_destination_id'));
    expect(CustomerPayment::count())->toBe(0);
})->with([
    'no account chosen' => ['none'],
    'an account of another customer' => ['another customer'],
]);

test('it refuses to start with a sale of another account', function () {
    CashSession::factory()->create();
    ['customer' => $customer, 'reynosa' => $reynosa, 'sale0995' => $sale0995] = castanonAccounts();

    expect(fn () => app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, $reynosa, PaymentMethod::Cash, '100', startAt: $sale0995))
        ->toThrow(failsOn('sale_id'));
    expect(CustomerPayment::count())->toBe(0);
});

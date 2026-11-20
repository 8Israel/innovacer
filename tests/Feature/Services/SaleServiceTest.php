<?php

use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleStatus;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashSessionService;
use App\Services\CustomerBalanceService;
use App\Services\CustomerPaymentService;
use App\Services\SaleAuthorization;
use App\Services\SaleService;

/**
 * Holds a sale of one product; without a customer it is a walk-in sale.
 */
function holdSale(Warehouse $warehouse, Product $product, string $quantity, ?Customer $customer = null, string $discountRate = '0'): Sale
{
    return app(SaleService::class)->hold(
        User::factory()->create(),
        $warehouse,
        [['product_id' => $product->id, 'quantity' => $quantity]],
        $customer,
        discountRate: $discountRate,
        holdNote: 'Regresa por él en la tarde',
    );
}

/**
 * A cash payment; the received amount defaults to the exact amount.
 *
 * @return array{method: PaymentMethod, amount: string, received_amount: string|null}
 */
function cash(string $amount, ?string $received = null): array
{
    return ['method' => PaymentMethod::Cash, 'amount' => $amount, 'received_amount' => $received];
}

test('it holds a pending sale without touching stock, inventory or payments', function () {
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '10', '2026-01-10');
    $operationsBefore = InventoryOperation::count();

    $sale = holdSale($warehouse, $product, '2');

    expect($sale->status)->toBe(SaleStatus::Pending);
    expect($sale->folio)->toBeNull();
    expect($sale->sold_at)->toBeNull();
    expect($sale->held_at)->not->toBeNull();
    expect($sale->hold_note)->toBe('Regresa por él en la tarde');
    expect($sale->inventory_operation_id)->toBeNull();
    expect($sale->total)->toBe('232.00');
    expect($stock->fresh()->quantity)->toBe('10.000');
    expect(InventoryOperation::count())->toBe($operationsBefore);
    expect(InventoryMovement::count())->toBe(0);
    expect(SalePayment::count())->toBe(0);
});

test('it charges a resumed sale taking the oldest lots first', function () {
    $session = CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $newerStock = stockLot($product, $warehouse, '10', '2026-02-10');
    $olderStock = stockLot($product, $warehouse, '3', '2026-01-10');
    $sale = holdSale($warehouse, $product, '5');
    $cashier = User::factory()->create();

    $completed = app(SaleService::class)->complete($sale, $cashier, [cash('580', '600')]);

    expect($completed->status)->toBe(SaleStatus::Completed);
    expect($completed->folio)->toBe('V-000001');
    expect($completed->sold_at)->not->toBeNull();
    expect($completed->user_id)->toBe($cashier->id);
    expect($completed->cash_session_id)->toBe($session->id);
    expect($completed->inventoryOperation->type)->toBe(InventoryOperationType::Sale);
    expect($olderStock->fresh()->quantity)->toBe('0.000');
    expect($newerStock->fresh()->quantity)->toBe('8.000');
    expect(InventoryMovement::orderBy('id')->get(['lot_id', 'sale_item_id', 'quantity', 'stock_before', 'stock_after'])->toArray())->toBe([
        ['lot_id' => $olderStock->lot_id, 'sale_item_id' => $completed->items->first()->id, 'quantity' => '-3.000', 'stock_before' => '13.000', 'stock_after' => '10.000'],
        ['lot_id' => $newerStock->lot_id, 'sale_item_id' => $completed->items->first()->id, 'quantity' => '-2.000', 'stock_before' => '10.000', 'stock_after' => '8.000'],
    ]);
    expect($completed->payments()->sole()->only(['cash_session_id', 'amount', 'received_amount', 'change_amount']))->toBe([
        'cash_session_id' => $session->id,
        'amount' => '580.00',
        'received_amount' => '600.00',
        'change_amount' => '20.00',
    ]);
});

test('it refuses to charge when the stock no longer covers the sale', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '3', '2026-01-10');
    $sale = holdSale($warehouse, $product, '5');

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), [cash('580')]))
        ->toThrow(failsOn('items'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($sale->fresh()->folio)->toBeNull();
    expect($stock->fresh()->quantity)->toBe('3.000');
    expect(InventoryMovement::count())->toBe(0);
    expect(SalePayment::count())->toBe(0);
});

test('it refuses to charge without an open register', function () {
    CashSession::factory()->closed()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1');

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), [cash('116')]))
        ->toThrow(failsOn('cash_session'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('10.000');
});

test('it charges the current price when it changed while the sale was pending', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1');
    $product->update(['unit_price' => 120]);

    $completed = app(SaleService::class)->complete($sale, User::factory()->create(), [cash('139.20')]);

    expect($completed->total)->toBe('139.20');
    expect($completed->items->first()->fresh()->unit_price)->toBe('120.00');
});

test('it refuses to charge a product that lost its price while pending', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1');
    $product->update(['unit_price' => null]);

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), [cash('116')]))
        ->toThrow(failsOn('items'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('10.000');
});

test('it refuses to hold a product without a price', function () {
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->withoutPrice()->create();

    expect(fn () => holdSale($warehouse, $product, '1'))->toThrow(failsOn('items'));
    expect(Sale::count())->toBe(0);
});

test('it chains customer and sale discounts and charges IVA on top of IEPS', function () {
    $warehouse = Warehouse::factory()->sellable()->create();
    $customer = Customer::factory()->create(['discount_rate' => 0.10]);
    $product = Product::factory()->create(['unit_price' => 1000, 'tax_rate' => 0.16, 'ieps_rate' => 0.08]);

    $sale = holdSale($warehouse, $product, '1', $customer, '0.05');

    expect($sale->items->first()->only(['discount_rate', 'discount_amount', 'subtotal', 'ieps_amount', 'iva_amount', 'total']))->toBe([
        'discount_rate' => '0.1450',
        'discount_amount' => '145.00',
        'subtotal' => '1000.00',
        'ieps_amount' => '68.40',
        'iva_amount' => '147.74',
        'total' => '1071.14',
    ]);
    expect($sale->only(['subtotal', 'discount_total', 'ieps_total', 'tax_total', 'total']))->toBe([
        'subtotal' => '1000.00',
        'discount_total' => '145.00',
        'ieps_total' => '68.40',
        'tax_total' => '147.74',
        'total' => '1071.14',
    ]);
});

test('it leaves the unpaid part on the account of a customer with unlimited credit', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '10', '2026-01-10');
    $customer = Customer::factory()->withCredit()->create(['discount_rate' => 0]);
    $sale = holdSale($warehouse, $product, '1', $customer);

    $completed = app(SaleService::class)->complete($sale, User::factory()->create(), [cash('50')]);

    expect($completed->status)->toBe(SaleStatus::Completed);
    expect(app(CustomerBalanceService::class)->balance($customer))->toBe('66.00');
});

test('it refuses a credit sale that would take Ernesto Castaño over his limit', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 48900, 'tax_rate' => 0, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '5', '2026-01-10');
    $ernesto = Customer::factory()->withCredit('50000')->create(['name' => 'Ernesto Castaño', 'discount_rate' => 0]);
    Sale::factory()->for($ernesto)->create(['total' => 3200]);
    $sale = holdSale($warehouse, $product, '1', $ernesto);

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), []))
        ->toThrow(failsOn('payments'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('5.000');
});

test('it accepts a credit sale that reaches the limit exactly', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 48900, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '5', '2026-01-10');
    $ernesto = Customer::factory()->withCredit('50000')->create(['discount_rate' => 0]);
    Sale::factory()->for($ernesto)->create(['total' => 3200]);
    $sale = holdSale($warehouse, $product, '1', $ernesto);

    $completed = app(SaleService::class)->complete($sale, User::factory()->create(), [cash('2100')]);

    expect($completed->status)->toBe(SaleStatus::Completed);
    expect(app(CustomerBalanceService::class)->balance($ernesto))->toBe('50000.00');
});

test('it rejects payments that do not fit the sale', function (array $payments, string $buyer) {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '10', '2026-01-10');
    $customer = match ($buyer) {
        'credit customer' => Customer::factory()->withCredit()->create(['discount_rate' => 0]),
        'cash customer' => Customer::factory()->create(['discount_rate' => 0]),
        'walk-in' => null,
    };
    $sale = holdSale($warehouse, $product, '1', $customer);

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), $payments))
        ->toThrow(failsOn('payments'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('10.000');
    expect(SalePayment::count())->toBe(0);
})->with([
    'more than the total' => [[cash('100'), ['method' => PaymentMethod::Card, 'amount' => '20']], 'credit customer'],
    'walk-in customer paying less than the total' => [[cash('100')], 'walk-in'],
    'customer without credit paying less than the total' => [[cash('100')], 'cash customer'],
    'cash received below the cash amount' => [[cash('116', '100')], 'credit customer'],
]);

test('it gives consecutive folios to charged sales', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '10', '2026-01-10');
    Sale::factory()->create(['folio' => 'V-000041']);
    $first = holdSale($warehouse, $product, '1');
    $second = holdSale($warehouse, $product, '1');

    $service = app(SaleService::class);
    $service->complete($second, User::factory()->create(), [cash('116')]);
    $service->complete($first, User::factory()->create(), [cash('116')]);

    expect($second->fresh()->folio)->toBe('V-000042');
    expect($first->fresh()->folio)->toBe('V-000043');
});

test('it discards a pending sale with its items', function () {
    $sale = Sale::factory()->pending()->has(SaleItem::factory(), 'items')->create();

    app(SaleService::class)->discard($sale);

    $this->assertModelMissing($sale);
    expect(SaleItem::count())->toBe(0);
});

test('it refuses to discard a completed sale', function () {
    $sale = Sale::factory()->has(SaleItem::factory(), 'items')->create();

    expect(fn () => app(SaleService::class)->discard($sale))->toThrow(failsOn('sale'));
    $this->assertModelExists($sale);
    expect(SaleItem::count())->toBe(1);
});

test('it refuses to sell from a warehouse that is not the sales floor', function () {
    $warehouse = Warehouse::factory()->create(['is_sellable' => false]);
    $product = Product::factory()->create(['unit_price' => 100]);

    expect(fn () => holdSale($warehouse, $product, '1'))->toThrow(failsOn('warehouse_id'));
    expect(Sale::count())->toBe(0);
});

test('it refuses a destination of another customer', function () {
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100]);
    $customer = Customer::factory()->create();
    $otherDestination = CustomerDestination::factory()->create();

    expect(fn () => app(SaleService::class)->hold(User::factory()->create(), $warehouse, [['product_id' => $product->id, 'quantity' => '1']], $customer, $otherDestination))
        ->toThrow(failsOn('customer_destination_id'));
    expect(Sale::count())->toBe(0);
});

test('it requires an authorization for an extra discount', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1', discountRate: '0.05');

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), [cash('110.20')]))
        ->toThrow(failsOn('authorization'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('10.000');
});

test('it records the clerk who authorized an extra discount', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1', discountRate: '0.05');
    $clerk = User::factory()->create();

    $completed = app(SaleService::class)->complete($sale, $clerk, [cash('110.20')], new SaleAuthorization($clerk, 'password', 'Cliente frecuente'));

    expect($completed->only(['total', 'authorized_by_id', 'authorization_reason']))->toBe([
        'total' => '110.20',
        'authorized_by_id' => $clerk->id,
        'authorization_reason' => 'Cliente frecuente',
    ]);
});

test('it rejects an authorization without the right password or a reason', function (string $password, ?string $reason) {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1', discountRate: '0.05');
    $owner = User::factory()->owner()->create();

    expect(fn () => app(SaleService::class)->complete($sale, $owner, [cash('110.20')], new SaleAuthorization($owner, $password, $reason)))
        ->toThrow(failsOn('authorization'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
})->with([
    'wrong password' => ['otra-cosa', 'Cliente frecuente'],
    'without reason' => ['password', null],
]);

test('it lets the owner authorize a credit sale over the limit', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 48900, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '5', '2026-01-10');
    $ernesto = Customer::factory()->withCredit('50000')->create(['discount_rate' => 0]);
    Sale::factory()->for($ernesto)->create(['total' => 3200]);
    $sale = holdSale($warehouse, $product, '1', $ernesto);
    $owner = User::factory()->owner()->create();

    $completed = app(SaleService::class)->complete($sale, User::factory()->create(), [], new SaleAuthorization($owner, 'password', 'Paga el viernes'));

    expect($completed->status)->toBe(SaleStatus::Completed);
    expect($completed->authorized_by_id)->toBe($owner->id);
    expect(app(CustomerBalanceService::class)->balance($ernesto))->toBe('52100.00');
});

test('it forbids a clerk to authorize a credit sale over the limit', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 48900, 'tax_rate' => 0, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '5', '2026-01-10');
    $ernesto = Customer::factory()->withCredit('50000')->create(['discount_rate' => 0]);
    Sale::factory()->for($ernesto)->create(['total' => 3200]);
    $sale = holdSale($warehouse, $product, '1', $ernesto);
    $clerk = User::factory()->create();

    expect(fn () => app(SaleService::class)->complete($sale, $clerk, [], new SaleAuthorization($clerk, 'password', 'Paga el viernes')))
        ->toThrow(failsOn('authorization'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('5.000');
});

/**
 * Sale #1043 from the design: 2 Ultra Nitrógeno 20 L at $1,250 paid with $2,500 in cash,
 * taking one piece from each of two lots in Mostrador.
 *
 * @return array{sale: Sale, product: Product, mostrador: Warehouse, session: CashSession, olderStock: Stock, newerStock: Stock}
 */
function sale1043(): array
{
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $product = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L', 'unit_price' => 1250, 'tax_rate' => 0, 'ieps_rate' => null]);
    $olderStock = stockLot($product, $mostrador, '1', '2026-09-01');
    $newerStock = stockLot($product, $mostrador, '13', '2026-10-03');
    $customer = Customer::factory()->create(['name' => 'Alejandro Carrasco', 'discount_rate' => 0]);
    $sale = app(SaleService::class)->complete(holdSale($mostrador, $product, '2', $customer), User::factory()->create(), [cash('2500')]);

    return compact('sale', 'product', 'mostrador', 'session', 'olderStock', 'newerStock');
}

test('it cancels sale 1043 returning the product to its lots and the cash from the register', function () {
    ['sale' => $sale, 'product' => $product, 'mostrador' => $mostrador, 'session' => $session, 'olderStock' => $olderStock, 'newerStock' => $newerStock] = sale1043();
    $owner = User::factory()->owner()->create();

    $canceled = app(SaleService::class)->cancel($sale, new SaleAuthorization($owner, 'password', 'El cliente se arrepintió'));

    expect($canceled->status)->toBe(SaleStatus::Canceled);
    expect($canceled->canceled_at)->not->toBeNull();
    expect($canceled->only(['canceled_by_id', 'cancel_reason']))->toBe(['canceled_by_id' => $owner->id, 'cancel_reason' => 'El cliente se arrepintió']);
    expect(stockOf($product, $mostrador))->toBe('14.000');
    expect($olderStock->fresh()->quantity)->toBe('1.000');
    expect($newerStock->fresh()->quantity)->toBe('13.000');
    $reversal = InventoryOperation::query()->where('type', InventoryOperationType::Reversal)->sole();
    expect($reversal->reversal_of_id)->toBe($sale->inventory_operation_id);
    expect($session->cashMovements()->sole()->only(['type', 'amount', 'beneficiary']))->toBe([
        'type' => CashMovementType::Outflow,
        'amount' => '2500.00',
        'beneficiary' => 'Alejandro Carrasco',
    ]);
    expect(app(CashSessionService::class)->expectedCash($session))->toBe('1000.00');
});

test('it forbids a clerk to cancel a sale', function () {
    ['sale' => $sale, 'product' => $product, 'mostrador' => $mostrador] = sale1043();
    $clerk = User::factory()->create();

    expect(fn () => app(SaleService::class)->cancel($sale, new SaleAuthorization($clerk, 'password', 'El cliente se arrepintió')))
        ->toThrow(failsOn('authorization'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);
    expect(stockOf($product, $mostrador))->toBe('12.000');
});

test('it refuses to cancel an invoiced sale', function () {
    ['sale' => $sale] = sale1043();
    $sale->invoices()->attach(Invoice::factory()->stamped()->create());

    expect(fn () => app(SaleService::class)->cancel($sale, new SaleAuthorization(User::factory()->owner()->create(), 'password', 'Precio equivocado')))
        ->toThrow(failsOn('sale'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);
});

test('it refuses to cancel a cash sale without an open register', function () {
    ['sale' => $sale, 'product' => $product, 'mostrador' => $mostrador] = sale1043();
    app(CashSessionService::class)->close(User::factory()->create(), '3500', '1000');

    expect(fn () => app(SaleService::class)->cancel($sale, new SaleAuthorization(User::factory()->owner()->create(), 'password', 'Precio equivocado')))
        ->toThrow(failsOn('cash_session'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);
    expect(stockOf($product, $mostrador))->toBe('12.000');
});

test('it refuses to cancel a sale that already has customer payments applied', function () {
    CashSession::factory()->create();
    $customer = Customer::factory()->withCredit()->create();
    $sale = Sale::factory()->for($customer)->create(['total' => 1500]);
    app(CustomerPaymentService::class)->register(User::factory()->create(), $customer, null, PaymentMethod::Cash, '500');

    expect(fn () => app(SaleService::class)->cancel($sale, new SaleAuthorization(User::factory()->owner()->create(), 'password', 'Capturada por error')))
        ->toThrow(failsOn('sale'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);
});

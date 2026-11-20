<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\Lot;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleStatus;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;

/**
 * Puts a lot of the product in the warehouse.
 */
function stockLot(Product $product, Warehouse $warehouse, string $quantity, string $receivedAt): Stock
{
    $lot = Lot::factory()->for($product)->create(['quantity_received' => $quantity, 'received_at' => $receivedAt]);

    return Stock::factory()->for($lot)->for($warehouse)->create(['product_id' => $product->id, 'quantity' => $quantity]);
}

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

test('it leaves the unpaid part of a customer sale on credit', function () {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    stockLot($product, $warehouse, '10', '2026-01-10');
    $sale = holdSale($warehouse, $product, '1', Customer::factory()->create(['discount_rate' => 0]));

    $completed = app(SaleService::class)->complete($sale, User::factory()->create(), [cash('50')]);

    expect($completed->status)->toBe(SaleStatus::Completed);
    expect($completed->payments()->sum('amount'))->toEqual(50);
});

test('it rejects payments that do not fit the sale', function (array $payments, ?bool $withCustomer) {
    CashSession::factory()->create();
    $warehouse = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $stock = stockLot($product, $warehouse, '10', '2026-01-10');
    $customer = $withCustomer ? Customer::factory()->create(['discount_rate' => 0]) : null;
    $sale = holdSale($warehouse, $product, '1', $customer);

    expect(fn () => app(SaleService::class)->complete($sale, User::factory()->create(), $payments))
        ->toThrow(failsOn('payments'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
    expect($stock->fresh()->quantity)->toBe('10.000');
    expect(SalePayment::count())->toBe(0);
})->with([
    'more than the total' => [[cash('100'), ['method' => PaymentMethod::Card, 'amount' => '20']], true],
    'walk-in customer paying less than the total' => [[cash('100')], false],
    'cash received below the cash amount' => [[cash('116', '100')], true],
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

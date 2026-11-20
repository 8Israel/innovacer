<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\InventoryMovement;
use App\Models\InventoryOperationType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashSessionService;
use App\Services\CustomerReturnService;
use App\Services\SaleService;

/**
 * Charges a cash sale of 6 Calcio 1 L at $185, paid in full ($999.00 with a 10 % customer discount).
 *
 * @return array{0: Sale, 1: Product, 2: Warehouse, 3: CashSession}
 */
function soldCalcio(?Customer $customer = null): array
{
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'unit_price' => 185, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($calcio, $mostrador, '10', '2026-09-28');
    $service = app(SaleService::class);
    $sale = $service->hold(User::factory()->create(), $mostrador, [['product_id' => $calcio->id, 'quantity' => '6']], $customer);
    $sale = $service->complete($sale, User::factory()->create(), [['method' => PaymentMethod::Cash, 'amount' => $sale->total]]);

    return [$sale, $calcio, $mostrador, $session];
}

test('it puts returned product back in its lot and refunds the cash from the register', function () {
    [$sale, $calcio, $mostrador, $session] = soldCalcio(Customer::factory()->create(['discount_rate' => 0.10]));
    $saleItem = $sale->items()->sole();

    $customerReturn = app(CustomerReturnService::class)->register(
        User::factory()->create(),
        $sale,
        [['sale_item_id' => $saleItem->id, 'quantity' => '1']],
        RefundMethod::Cash,
        reason: 'El cliente compró de más',
    );

    expect($customerReturn->only(['folio', 'refund_amount', 'restocked', 'cash_session_id']))->toBe([
        'folio' => 'D-000001',
        'refund_amount' => '166.50',
        'restocked' => true,
        'cash_session_id' => $session->id,
    ]);
    expect($customerReturn->items()->sole()->only(['quantity', 'refund_amount']))->toBe(['quantity' => '1.000', 'refund_amount' => '166.50']);
    expect($customerReturn->inventoryOperation->type)->toBe(InventoryOperationType::Return);
    expect(stockOf($calcio, $mostrador))->toBe('5.000');
    expect($customerReturn->inventoryOperation->movements()->sole()->only(['sale_item_id', 'quantity', 'stock_before', 'stock_after']))->toBe([
        'sale_item_id' => $saleItem->id,
        'quantity' => '1.000',
        'stock_before' => '4.000',
        'stock_after' => '5.000',
    ]);
    expect(app(CashSessionService::class)->expectedCash($session))->toBe('1832.50');
});

test('it records waste without putting the product back in stock', function () {
    [$sale, $calcio, $mostrador] = soldCalcio(Customer::factory()->create(['discount_rate' => 0.10]));

    $customerReturn = app(CustomerReturnService::class)->register(
        User::factory()->create(),
        $sale,
        [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '1']],
        RefundMethod::Cash,
        restock: false,
        reason: 'Producto dañado',
    );

    expect($customerReturn->restocked)->toBeFalse();
    expect($customerReturn->inventory_operation_id)->toBeNull();
    expect(stockOf($calcio, $mostrador))->toBe('4.000');
});

test('it refuses to return more than what was sold', function () {
    [$sale] = soldCalcio(Customer::factory()->create(['discount_rate' => 0]));
    $saleItem = $sale->items()->sole();
    $service = app(CustomerReturnService::class);
    $service->register(User::factory()->create(), $sale, [['sale_item_id' => $saleItem->id, 'quantity' => '5']], RefundMethod::Cash);

    expect(fn () => $service->register(User::factory()->create(), $sale, [['sale_item_id' => $saleItem->id, 'quantity' => '2']], RefundMethod::Cash))
        ->toThrow(failsOn('items'));
    expect(CustomerReturn::count())->toBe(1);
});

test('it refuses a cash refund without an open register', function () {
    [$sale, $calcio, $mostrador] = soldCalcio(Customer::factory()->create(['discount_rate' => 0]));
    app(CashSessionService::class)->close(User::factory()->create(), '2110', '1000');
    $movementsBefore = InventoryMovement::count();

    expect(fn () => app(CustomerReturnService::class)->register(User::factory()->create(), $sale, [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '1']], RefundMethod::Cash))
        ->toThrow(failsOn('cash_session'));
    expect(CustomerReturn::count())->toBe(0);
    expect(InventoryMovement::count())->toBe($movementsBefore);
    expect(stockOf($calcio, $mostrador))->toBe('4.000');
});

test('it refuses to credit the account of a walk-in sale', function () {
    [$sale] = soldCalcio();

    expect(fn () => app(CustomerReturnService::class)->register(User::factory()->create(), $sale, [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '1']], RefundMethod::AccountCredit))
        ->toThrow(failsOn('refund_method'));
    expect(CustomerReturn::count())->toBe(0);
});

test('it credits the customer account without taking cash from the register', function () {
    [$sale, , , $session] = soldCalcio(Customer::factory()->create(['discount_rate' => 0.10]));

    $customerReturn = app(CustomerReturnService::class)->register(User::factory()->create(), $sale, [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '1']], RefundMethod::AccountCredit);

    expect($customerReturn->refund_method)->toBe(RefundMethod::AccountCredit);
    expect($customerReturn->refund_amount)->toBe('166.50');
    expect(app(CashSessionService::class)->expectedCash($session))->toBe('1999.00');
});

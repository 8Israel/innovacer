<?php

use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerReturnService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Services\SupplierDebtService;
use App\Services\SupplierPaymentService;
use Illuminate\Support\Carbon;

/**
 * Ortitec from docs/diseno/Proveedor.html, in small: 50 Sumo 1 L at $280 and
 * 30 Core 20 L at $1,600 left in consignment in September, of which 40 Sumo
 * and 30 Core were sold.
 *
 * @return array{ortitec: Supplier, sumo: Product, core: Product, mostrador: Warehouse, sumoSale: Sale}
 */
function ortitecDebt(): array
{
    CashSession::factory()->create(['opening_cash' => 100000]);
    $ortitec = Supplier::factory()->create(['name' => 'Ortitec']);
    $mostrador = Warehouse::factory()->sellable()->create();
    $sumo = Product::factory()->create(['name' => 'Sumo 1 L', 'unit_price' => 400, 'tax_rate' => 0, 'ieps_rate' => null]);
    $core = Product::factory()->create(['name' => 'Core 20 L', 'unit_price' => 2200, 'tax_rate' => 0, 'ieps_rate' => null]);
    $inventory = app(InventoryService::class);
    $user = User::factory()->owner()->create();

    $inventory->receive($user, $ortitec, $mostrador, [['product_id' => $core->id, 'quantity' => '30', 'unit_cost' => '1600']], true, receivedAt: Carbon::parse('2026-09-01'));
    $inventory->receive($user, $ortitec, $mostrador, [['product_id' => $sumo->id, 'quantity' => '50', 'unit_cost' => '280']], true, receivedAt: Carbon::parse('2026-09-10'));

    $sales = app(SaleService::class);
    $sales->sell($user, $mostrador, [['product_id' => $core->id, 'quantity' => '30']], [['method' => PaymentMethod::Cash, 'amount' => '66000']]);
    $sumoSale = $sales->sell($user, $mostrador, [['product_id' => $sumo->id, 'quantity' => '40']], [['method' => PaymentMethod::Cash, 'amount' => '16000']]);

    return compact('ortitec', 'sumo', 'core', 'mostrador', 'sumoSale');
}

test('a consignment supplier is owed only what was sold, at cost', function () {
    ['ortitec' => $ortitec] = ortitecDebt();
    $debts = app(SupplierDebtService::class);

    expect($debts->pending($ortitec))->toBe('59200.00');
    expect($debts->products($ortitec)->map(fn (array $row) => [$row['product']->name, $row['unpaid_quantity'], $row['pending']])->all())->toBe([
        ['Core 20 L', '30.000', '48000.00'],
        ['Sumo 1 L', '40.000', '11200.00'],
    ]);
    expect($debts->unsold($ortitec))->toBe(['value' => '2800.00', 'quantity' => '10.000']);
});

test('a restocked return is no longer owed to the supplier', function () {
    ['ortitec' => $ortitec, 'sumoSale' => $sumoSale] = ortitecDebt();

    app(CustomerReturnService::class)->register(User::factory()->create(), $sumoSale, [['sale_item_id' => $sumoSale->items()->sole()->id, 'quantity' => '2']], RefundMethod::Cash);

    expect(app(SupplierDebtService::class)->pending($ortitec))->toBe('58640.00');
});

test('a bought lot is owed in full from the day it arrives', function () {
    CashSession::factory()->create();
    $nh = Supplier::factory()->purchase()->create();
    app(InventoryService::class)->receive(User::factory()->create(), $nh, Warehouse::factory()->create(), [['product_id' => Product::factory()->create()->id, 'quantity' => '12', 'unit_cost' => '350']], false);

    expect(app(SupplierDebtService::class)->pending($nh))->toBe('4200.00');
});

test('a payment settles the oldest debt first and leaves the rest pending', function () {
    ['ortitec' => $ortitec, 'core' => $core, 'sumo' => $sumo] = ortitecDebt();

    $payment = app(SupplierPaymentService::class)->pay(User::factory()->owner()->create(), $ortitec, PaymentMethod::Transfer, '50250', '8812');

    expect($payment->allocations()->with('lot')->get()->map(fn ($allocation) => [$allocation->lot->product_id, $allocation->quantity, $allocation->amount])->all())->toBe([
        [$core->id, '30.000', '48000.00'],
        [$sumo->id, '8.036', '2250.00'],
    ]);
    expect(app(SupplierDebtService::class)->pending($ortitec))->toBe('8950.00');
});

test('a payment can be spread by hand among products', function () {
    ['ortitec' => $ortitec, 'core' => $core, 'sumo' => $sumo] = ortitecDebt();

    app(SupplierPaymentService::class)->pay(User::factory()->owner()->create(), $ortitec, PaymentMethod::Transfer, '15000', productAmounts: [$sumo->id => '11200', $core->id => '3800']);

    expect(app(SupplierDebtService::class)->products($ortitec)->map(fn (array $row) => [$row['product']->name, $row['pending']])->all())
        ->toBe([['Core 20 L', '44200.00']]);
});

test('it refuses to pay more than is owed or to spread a different amount', function () {
    ['ortitec' => $ortitec, 'sumo' => $sumo, 'core' => $core] = ortitecDebt();
    $owner = User::factory()->owner()->create();
    $payments = app(SupplierPaymentService::class);

    expect(fn () => $payments->pay($owner, $ortitec, PaymentMethod::Transfer, '60000'))->toThrow(failsOn('amount'));
    expect(fn () => $payments->pay($owner, $ortitec, PaymentMethod::Transfer, '5000', productAmounts: [$sumo->id => '4000']))->toThrow(failsOn('product_amounts'));
    expect(fn () => $payments->pay($owner, $ortitec, PaymentMethod::Transfer, '12000', productAmounts: [$sumo->id => '12000']))->toThrow(failsOn('product_amounts'));
    expect(SupplierPayment::count())->toBe(0);
});

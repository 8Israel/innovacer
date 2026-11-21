<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerReturnService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * Two October sales of Calcio 1 L ($185, lot cost $120): 6 to Francisco for Reynosa
 * with his 10 % discount on the 8th, and 2 to a walk-in customer on the 9th.
 *
 * @return array{reynosaSale: Sale, calcio: Product}
 */
function octoberCalcioSales(): array
{
    app(SettingsService::class)->save(['business_name' => 'Innovacer']);
    CashSession::factory()->create(['opening_cash' => 1000]);
    $mostrador = Warehouse::factory()->sellable()->create();
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'unit_price' => 185, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($calcio, $mostrador, '20', '2026-10-01')->lot->update(['unit_cost' => 120]);
    $francisco = Customer::factory()->create(['name' => 'Francisco Castañón', 'discount_rate' => 0.10]);
    $reynosa = CustomerDestination::factory()->for($francisco)->create(['name' => 'Reynosa']);
    $sales = app(SaleService::class);
    $user = User::factory()->create();

    test()->travelTo(CarbonImmutable::parse('2026-10-08 12:00'));
    $reynosaSale = $sales->sell($user, $mostrador, [['product_id' => $calcio->id, 'quantity' => '6']], [['method' => PaymentMethod::Cash, 'amount' => '999']], $francisco, $reynosa);
    test()->travelTo(CarbonImmutable::parse('2026-10-09 12:00'));
    $sales->sell($user, $mostrador, [['product_id' => $calcio->id, 'quantity' => '2']], [['method' => PaymentMethod::Cash, 'amount' => '370']]);

    return compact('reynosaSale', 'calcio');
}

test('profit is the sale without taxes less the cost of its lots, net of returns', function () {
    ['reynosaSale' => $reynosaSale] = octoberCalcioSales();
    app(CustomerReturnService::class)->register(User::factory()->create(), $reynosaSale, [['sale_item_id' => $reynosaSale->items()->sole()->id, 'quantity' => '1']], RefundMethod::Cash);
    $reports = app(ReportService::class);

    $lines = $reports->lines(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-09'));

    expect($reports->summary($lines))->toBe([
        'sales' => '1202.50',
        'net' => '1202.50',
        'profit' => '362.50',
        'margin' => '30.1',
        'days_with_sales' => 2,
        'missing_cost' => false,
    ]);
    expect($reports->salesByDestination($lines)->all())->toBe([
        ['destination' => 'Reynosa', 'sales' => '832.50'],
        ['destination' => null, 'sales' => '370.00'],
    ]);
    expect($reports->salesByDay($lines, CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2026-10-09'))->all())
        ->toBe(['2026-10-07' => '0.00', '2026-10-08' => '832.50', '2026-10-09' => '370.00']);
});

test('inventory at cost separates what is own from what belongs to suppliers', function () {
    $mostrador = Warehouse::factory()->create();
    stockLot(Product::factory()->create(), $mostrador, '10', '2026-10-01')->lot->update(['unit_cost' => 100]);
    $ortitec = Supplier::factory()->create(['name' => 'Ortitec']);
    stockLot(Product::factory()->create(), $mostrador, '4', '2026-10-01', $ortitec)->lot->update(['unit_cost' => 250]);

    expect(app(ReportService::class)->inventoryAtCost()->map(fn (array $row) => [$row['own'], $row['value']])->all())
        ->toBe([[true, '1000.00'], [false, '1000.00']]);
});

test('the owner sees the month report', function () {
    octoberCalcioSales();

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::reports.index')
        ->assertSeeInOrder(['Del 1 al 9 de octubre de 2026', 'Ventas', '$1,369.00', '2 días con venta', 'Utilidad bruta', '$409.00', 'Margen 29.9%'])
        ->assertSeeInOrder(['Ventas por destino', 'Reynosa', '$999.00', 'Sin destino', '$370.00'])
        ->assertSeeInOrder(['Más vendidos y su utilidad', 'Calcio 1 L', 'Innovacer', '8', '$1,369.00', '$409.00', '29.9%'])
        ->set('period', 'today')
        ->assertSee('$370.00')
        ->assertDontSee('$1,369.00');
});

test('the report exports the lines sold as a CSV', function () {
    octoberCalcioSales();

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::reports.index')
        ->call('export')
        ->assertFileDownloaded('ventas-2026-10-01-a-2026-10-09.csv');
});

test('the clerk cannot open the reports', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('reports.index'))
        ->assertRedirect(route('pos.index'));
});

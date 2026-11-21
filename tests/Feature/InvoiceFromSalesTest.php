<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerReturnService;
use App\Services\InvoiceFromSalesService;
use App\Services\SaleService;
use Livewire\Livewire;

/**
 * Ernesto Castaño (10 % discount, credit) with two sales: #1 of 3 Core 20 L
 * (no IVA) and 8 Calcio 1 L (IVA 16 %, IEPS 8 %) paid by transfer, and #2 of
 * 1 Core 20 L on credit, unpaid.
 *
 * @return array{customer: Customer, paid: Sale, owed: Sale, core: Product, calcio: Product}
 */
function ernestoSales(): array
{
    CashSession::factory()->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $core = Product::factory()->create(['name' => 'Core 20 L', 'unit_price' => 2100, 'tax_rate' => 0, 'ieps_rate' => null, 'product_key' => '10171500', 'unit_key' => 'H87']);
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'unit_price' => 185, 'tax_rate' => 0.16, 'ieps_rate' => 0.08, 'product_key' => '10171500', 'unit_key' => 'H87']);
    stockLot($core, $mostrador, '10', '2026-09-01');
    stockLot($calcio, $mostrador, '20', '2026-09-01');
    $customer = Customer::factory()->withCredit()->create([
        'name' => 'Ernesto Castaño', 'discount_rate' => 0.10, 'rfc' => 'CAGE850312QK4', 'tax_system' => '612', 'zip_code' => '27000', 'cfdi_use' => 'G01', 'facturapi_id' => 'cus_ernesto',
    ]);
    $sales = app(SaleService::class);
    $user = User::factory()->owner()->create();
    $quote = $sales->quote([['product_id' => $core->id, 'quantity' => '3'], ['product_id' => $calcio->id, 'quantity' => '8']], $customer);

    $paid = $sales->sell($user, $mostrador, [['product_id' => $core->id, 'quantity' => '3'], ['product_id' => $calcio->id, 'quantity' => '8']], [['method' => PaymentMethod::Transfer, 'amount' => $quote['total'], 'reference' => '4471']], $customer);
    $owed = $sales->sell($user, $mostrador, [['product_id' => $core->id, 'quantity' => '1']], [], $customer);

    return compact('customer', 'paid', 'owed', 'core', 'calcio');
}

test('the concepts add up to what the sales charged, discounts and taxes included', function () {
    ['paid' => $paid, 'owed' => $owed] = ernestoSales();
    $service = app(InvoiceFromSalesService::class);

    $concepts = $service->concepts(collect([$paid, $owed]));

    expect($concepts->map(fn (array $line) => [$line['product']->name, $line['quantity'], $line['unit_price'], $line['discount']])->all())->toBe([
        ['Calcio 1 L', '8.000', '185.00', '148.00'],
        ['Core 20 L', '4.000', '2100.00', '840.00'],
    ]);
    expect($concepts->reduce(fn ($carry, $line) => bcadd($carry, $line['total'], 2), '0'))->toBe(bcadd($paid->total, $owed->total, 2));
});

test('a returned item is not invoiced', function () {
    ['paid' => $paid, 'calcio' => $calcio] = ernestoSales();
    app(CustomerReturnService::class)->register(User::factory()->create(), $paid, [['sale_item_id' => $paid->items()->where('product_id', $calcio->id)->value('id'), 'quantity' => '8']], RefundMethod::Cash);

    expect(app(InvoiceFromSalesService::class)->concepts(collect([$paid]))->pluck('product.name')->all())->toBe(['Core 20 L']);
});

test('the payment terms follow how the sales were paid', function () {
    ['paid' => $paid, 'owed' => $owed] = ernestoSales();
    $service = app(InvoiceFromSalesService::class);

    expect($service->paymentTerms(collect([$paid])))->toBe(['payment_method' => 'PUE', 'payment_form' => '03']);
    expect($service->paymentTerms(collect([$paid, $owed])))->toBe(['payment_method' => 'PPD', 'payment_form' => '99']);
});

test('it blocks the invoice while a product has no SAT key or the customer misses fiscal data', function () {
    ['customer' => $customer, 'paid' => $paid, 'calcio' => $calcio] = ernestoSales();
    $calcio->update(['product_key' => null]);
    $customer->update(['zip_code' => null]);

    expect(fn () => app(InvoiceFromSalesService::class)->create($customer, [$paid->id], 'G01'))->toThrow(failsOn('invoice'));
    expect(Invoice::count())->toBe(0);
});

test('a sale is invoiced only once', function () {
    ['customer' => $customer, 'paid' => $paid] = ernestoSales();
    $service = app(InvoiceFromSalesService::class);
    $service->create($customer, [$paid->id], 'G01');

    expect(fn () => $service->create($customer, [$paid->id], 'G01'))->toThrow(failsOn('sale_ids'));
    expect($service->uninvoicedSales($customer)->pluck('sale.id')->all())->not->toContain($paid->id);
});

test('the page builds the invoice from the sale chosen in its detail and stamps it', function () {
    ['customer' => $customer, 'paid' => $paid, 'owed' => $owed] = ernestoSales();
    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->once()->withArgs(fn (array $data) => $data['payment_method'] === 'PUE'
            && $data['items'][0]['discount'] === 148.0
            && $data['items'][0]['product']['taxes'] === [['type' => 'IVA', 'rate' => 0.16], ['type' => 'IEPS', 'rate' => 0.08]])
            ->andReturn(['id' => 'inv_1', 'uuid' => '6F1A2B3C-0000-4000-8000-000000000002', 'series' => 'A', 'folio_number' => 3]);
    });

    Livewire::withQueryParams(['venta' => $paid->id])
        ->actingAs(User::factory()->create())
        ->test('pages::invoices.from-sales')
        ->assertSet('customer_id', $customer->id)
        ->assertSet('sale_ids', [$paid->id])
        ->assertSee('Una sola exhibición')
        ->assertSee($owed->folio)
        ->call('stamp')
        ->assertHasNoErrors()
        ->assertRedirect(route('invoices.index'));

    $invoice = Invoice::query()->sole();
    expect($invoice->only(['status', 'payment_method', 'payment_form', 'total']))->toBe(['status' => InvoiceStatus::Stamped, 'payment_method' => 'PUE', 'payment_form' => '03', 'total' => $paid->total]);
    expect($invoice->sales()->pluck('sales.id')->all())->toBe([$paid->id]);
});

test('the clerk can invoice but the sale detail hides Facturar once invoiced', function () {
    ['customer' => $customer, 'paid' => $paid] = ernestoSales();

    $this->actingAs(User::factory()->create())->get(route('sales.show', $paid))->assertSee('Facturar');

    app(InvoiceFromSalesService::class)->create($customer, [$paid->id], 'G01')->forceFill(['status' => InvoiceStatus::Stamped])->save();

    $this->actingAs(User::factory()->create())->get(route('sales.show', $paid))->assertDontSee('Facturar');
});

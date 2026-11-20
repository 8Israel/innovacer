<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerBalanceService;
use Livewire\Livewire;

/**
 * The counter with Ultra Nitrógeno (12 in stock at $1,250) and Calcio 1 L (20 at $185).
 *
 * @return array{mostrador: Warehouse, nitrogeno: Product, calcio: Product}
 */
function counter(): array
{
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $nitrogeno = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L', 'sku' => 'UNI-20', 'barcode' => '7501234500021', 'unit_price' => 1250, 'wholesale_price' => null, 'tax_rate' => 0, 'ieps_rate' => null]);
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'sku' => 'CAL-01', 'unit_price' => 185, 'wholesale_price' => null, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($nitrogeno, $mostrador, '12', '2026-10-01');
    stockLot($calcio, $mostrador, '20', '2026-10-01');

    return compact('mostrador', 'nitrogeno', 'calcio');
}

test('it is the start screen and warns when the register is closed', function () {
    counter();

    $this->actingAs(User::factory()->create())
        ->get(route('pos.index'))
        ->assertOk()
        ->assertSee('Nueva venta')
        ->assertSee('Sale de Mostrador Innovacer')
        ->assertSee('La caja está cerrada');
});

test('it adds a scanned product and shows the stock that will be left', function () {
    counter();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->set('search', '7501234500021')
        ->call('addFirstResult')
        ->set('search', 'UNI-20')
        ->call('addFirstResult')
        ->assertSee('Ultra Nitrógeno 20 L')
        ->assertSee('12 → quedan 10')
        ->assertSee('$2,500.00');
});

test('it refuses to add a product without a price', function () {
    $product = Product::factory()->withoutPrice()->create(['name' => 'Manguera de riego 16 mm']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $product->id)
        ->assertSet('lines', [])
        ->assertSee('Manguera de riego 16 mm no tiene precio público, así que no se puede vender.');
});

test('it charges a cash sale and shows the change to hand back', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno, 'calcio' => $calcio] = counter();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->call('addProduct', $nitrogeno->id)
        ->call('addProduct', $calcio->id)
        ->set('received_amount', '3000')
        ->call('charge')
        ->assertHasNoErrors()
        ->assertSet('lines', [])
        ->assertSet('registeredSale.folio', 'V-000001')
        ->assertSet('registeredSale.change', '315.00');

    expect(Sale::query()->sole()->only(['status', 'total']))->toBe(['status' => SaleStatus::Completed, 'total' => '2685.00']);
});

test('it applies the customer discount and charges on credit to an account', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno] = counter();
    $customer = Customer::factory()->withCredit()->create(['name' => 'Francisco Castañón', 'discount_rate' => 0.10]);
    $reynosa = CustomerDestination::factory()->for($customer)->create(['name' => 'Reynosa']);
    CustomerDestination::factory()->for($customer)->create(['name' => 'Jalincher']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->call('selectCustomer', $customer->id)
        ->set('customer_destination_id', $reynosa->id)
        ->assertSee('Descuento fijo 10%')
        ->assertSee('$1,125.00')
        ->set('payment_method', 'credit')
        ->call('charge')
        ->assertHasNoErrors();

    expect(Sale::query()->sole()->customer_destination_id)->toBe($reynosa->id);
    expect(app(CustomerBalanceService::class)->destinationBalance($reynosa))->toBe('1125.00');
});

test('it shows why a sale cannot be charged and keeps the cart', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno] = counter();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->set('lines.'.$nitrogeno->id.'.quantity', '15')
        ->call('charge')
        ->assertHasErrors('items')
        ->assertSee('No hay suficiente existencia de Ultra Nitrógeno 20 L en Mostrador Innovacer')
        ->assertSet('lines.'.$nitrogeno->id.'.quantity', '15');

    expect(Sale::count())->toBe(0);
});

test('it registers a customer on the spot and a new account for them', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->set('new_customer_name', 'Ing. Meléndez')
        ->set('new_customer_credit', true)
        ->call('createCustomer')
        ->assertHasNoErrors()
        ->set('new_destination_name', 'Rancho El Nogal')
        ->call('createDestination')
        ->assertHasNoErrors();

    $customer = Customer::query()->sole();
    expect($customer->only(['name', 'credit_enabled']))->toBe(['name' => 'Ing. Meléndez', 'credit_enabled' => true]);
    expect($customer->destinations()->sole()->name)->toBe('Rancho El Nogal');
});

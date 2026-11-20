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

test('it charges a sale with several payment methods', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno, 'calcio' => $calcio] = counter();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->call('addProduct', $calcio->id)
        ->call('openSplitPayment')
        ->set('split_payments.0.amount', '1000')
        ->set('split_payments.0.received_amount', '1000')
        ->call('addSplitRow')
        ->assertSet('split_payments.1.amount', '435.00')
        ->set('split_payments.1.reference', 'ref. 4471')
        ->call('chargeSplit')
        ->assertHasNoErrors();

    expect(Sale::query()->sole()->payments()->orderBy('id')->get(['method', 'amount', 'reference'])->map(fn ($payment) => [$payment->method->value, $payment->amount, $payment->reference])->all())->toBe([
        ['cash', '1000.00', null],
        ['transfer', '435.00', 'ref. 4471'],
    ]);
});

test('it does not leave part of a mixed payment unpaid unless it goes on credit', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno] = counter();
    $customer = Customer::factory()->withCredit()->create(['discount_rate' => 0]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->call('selectCustomer', $customer->id)
        ->call('openSplitPayment')
        ->set('split_payments.0.amount', '1000')
        ->call('chargeSplit')
        ->assertHasErrors('payments');

    expect(Sale::count())->toBe(0);

    $component->set('leave_rest_on_credit', true)->call('chargeSplit')->assertHasNoErrors();

    expect(app(CustomerBalanceService::class)->balance($customer))->toBe('250.00');
});

test('it asks for an authorization before charging an extra discount', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno] = counter();
    $clerk = User::factory()->create(['name' => 'Encargada']);

    $component = Livewire::actingAs($clerk)
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->set('extra_discount', '5')
        ->assertSee('$1,187.50')
        ->call('charge')
        ->assertSet('authorization_needed_for', 'Descuento extra de 5% en la venta')
        ->assertSet('authorization_owner_only', false);

    expect(Sale::count())->toBe(0);

    $component
        ->set('authorizer_id', $clerk->id)
        ->set('authorizer_password', 'password')
        ->set('authorization_note', 'Cliente frecuente')
        ->call('authorizeCharge')
        ->assertHasNoErrors();

    expect(Sale::query()->sole()->only(['total', 'authorized_by_id', 'authorization_reason']))->toBe([
        'total' => '1187.50',
        'authorized_by_id' => $clerk->id,
        'authorization_reason' => 'Cliente frecuente',
    ]);
});

test('it only lets the owner authorize going over the credit limit', function () {
    CashSession::factory()->create();
    ['nitrogeno' => $nitrogeno] = counter();
    $ernesto = Customer::factory()->withCredit('1000')->create(['discount_rate' => 0]);
    $owner = User::factory()->owner()->create();
    $clerk = User::factory()->create();

    $component = Livewire::actingAs($clerk)
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->call('selectCustomer', $ernesto->id)
        ->set('payment_method', 'credit')
        ->call('charge')
        ->assertSet('authorization_owner_only', true);

    expect($component->get('authorization_needed_for'))->toContain('rebasa su límite de crédito');
    expect($component->instance()->authorizers->pluck('id')->all())->toBe([$owner->id]);

    $component
        ->set('authorizer_id', $owner->id)
        ->set('authorizer_password', 'otra-cosa')
        ->set('authorization_note', 'Paga el viernes')
        ->call('authorizeCharge')
        ->assertHasErrors('authorization');

    expect(Sale::count())->toBe(0);

    $component
        ->set('authorizer_password', 'password')
        ->call('authorizeCharge')
        ->assertHasNoErrors();

    expect(Sale::query()->sole()->authorized_by_id)->toBe($owner->id);
});

test('it holds a sale without touching stock and resumes it later', function () {
    CashSession::factory()->create();
    ['mostrador' => $mostrador, 'nitrogeno' => $nitrogeno] = counter();
    $customer = Customer::factory()->create(['name' => 'Ing. Meléndez', 'discount_rate' => 0]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('addProduct', $nitrogeno->id)
        ->call('selectCustomer', $customer->id)
        ->set('hold_note', 'Viene por ellas a las 4')
        ->call('holdSale')
        ->assertSet('lines', []);

    $pending = Sale::query()->sole();
    expect($pending->status)->toBe(SaleStatus::Pending);
    expect(stockOf($nitrogeno, $mostrador))->toBe('12.000');

    $component
        ->assertSee('Viene por ellas a las 4')
        ->call('resumeSale', $pending->id)
        ->assertSet('resuming_sale_id', $pending->id)
        ->assertSet('customer_id', $customer->id)
        ->assertSet('lines.'.$nitrogeno->id.'.quantity', '1')
        ->call('charge')
        ->assertHasNoErrors();

    $this->assertModelMissing($pending);
    expect(Sale::query()->sole()->status)->toBe(SaleStatus::Completed);
    expect(stockOf($nitrogeno, $mostrador))->toBe('11.000');
});

test('it discards a pending sale from the list', function () {
    $pending = Sale::factory()->pending()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::pos.index')
        ->call('discardPendingSale', $pending->id);

    $this->assertModelMissing($pending);
});

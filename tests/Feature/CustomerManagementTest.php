<?php

use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('customers page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('customers.index'))->assertOk();
});

test('a customer can be created', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::customers.show')
        ->set('name', 'Agroquímicos del Bajío SA de CV')
        ->set('rfc', 'abc010101ab1')
        ->set('email', 'contacto@agrobajio.mx')
        ->set('tax_system', '601')
        ->set('cfdi_use', 'G03')
        ->set('zip_code', '38000')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('customers', [
        'rfc' => 'ABC010101AB1',
        'name' => 'Agroquímicos del Bajío SA de CV',
    ]);
});

test('creating a customer only requires the name; tax data is optional', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::customers.show')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertHasNoErrors(['rfc', 'tax_system', 'cfdi_use', 'zip_code']);

    Livewire::test('pages::customers.show')
        ->set('name', 'Alejandro Carrasco')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::query()->sole()->only(['name', 'rfc', 'credit_enabled']))->toBe(['name' => 'Alejandro Carrasco', 'rfc' => null, 'credit_enabled' => false]);
});

test('a customer can be updated', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create(['name' => 'Original']);

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSet('name', 'Original')
        ->set('name', 'Updated name')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->name)->toBe('Updated name');
});

test('a customer can be deleted', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->call('delete')
        ->assertRedirect(route('customers.index'));

    expect(Customer::find($customer->id))->toBeNull();
});

test('a customer with sales is deactivated instead of deleted', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    Sale::factory()->for($customer)->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])->call('delete');

    expect($customer->fresh()->is_active)->toBeFalse();
});

test('the clerk sets the credit with its limit and the fixed discount', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create(['name' => 'Ernesto Castaño', 'discount_rate' => 0]);

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->set('kind', 'credit')
        ->set('unlimited_credit', false)
        ->set('credit_limit', '50000')
        ->set('discount_percent', '10')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->only(['credit_enabled', 'credit_limit', 'discount_rate']))->toBe(['credit_enabled' => true, 'credit_limit' => '50000.00', 'discount_rate' => '0.1000']);
});

test('a credit customer with a limit needs the limit amount', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::customers.show', ['customer' => Customer::factory()->create()])
        ->set('kind', 'credit')
        ->set('unlimited_credit', false)
        ->set('credit_limit', '')
        ->call('save')
        ->assertHasErrors('credit_limit');
});

test('it adds and renames the accounts of a customer showing their balance', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->withCredit()->create();
    $reynosa = CustomerDestination::factory()->for($customer)->create(['name' => 'Reynosa']);
    Sale::factory()->for($customer)->for($reynosa)->create(['total' => 8200]);

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->assertSee('$8,200.00')
        ->set('new_destination_name', 'Jalincher')
        ->call('addDestination')
        ->assertHasNoErrors()
        ->set("destination_names.{$reynosa->id}", 'Reynosa Norte')
        ->call('save');

    expect($customer->destinations()->orderBy('id')->pluck('name')->all())->toBe(['Reynosa Norte', 'Jalincher']);
});

test('the list shows what each credit customer owes and since when', function () {
    $this->actingAs(User::factory()->create());
    $melendez = Customer::factory()->withCredit()->create(['name' => 'Ing. Meléndez', 'phone' => null]);
    $sale = Sale::factory()->for($melendez)->create(['total' => 20650, 'sold_at' => now()->subDays(60)]);
    $payment = CustomerPayment::factory()->for($melendez)->create(['amount' => 2000, 'paid_at' => now()->subDays(37)]);
    CustomerPaymentAllocation::factory()->for($payment)->for($sale)->create(['amount' => 2000]);
    $castanon = Customer::factory()->withCredit()->create(['name' => 'Francisco Castañón', 'discount_rate' => 0.10]);
    CustomerDestination::factory()->for($castanon)->create(['name' => 'Reynosa']);
    Sale::factory()->for($castanon)->create(['total' => 12400, 'sold_at' => now()->subDay()]);
    Customer::factory()->create(['name' => 'Alejandro Carrasco']);

    Livewire::test('pages::customers.index')
        ->assertSee('$31,050.00')
        ->assertSee('2 clientes con saldo')
        ->assertSee('1 cliente')
        ->assertSee('$18,650.00 pendientes')
        ->assertSeeInOrder(['Ing. Meléndez', 'Tel. [POR CAPTURAR]', 'Crédito', '1 cuenta', '$18,650.00', '37'])
        ->assertSeeInOrder(['Francisco Castañón', 'Descuento fijo 10%', 'Reynosa', '$12,400.00'])
        ->assertDontSee('Alejandro Carrasco')
        ->set('only_with_balance', false)
        ->assertSee('Alejandro Carrasco');
});

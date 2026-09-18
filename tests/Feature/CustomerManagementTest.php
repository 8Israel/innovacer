<?php

use App\Models\Customer;
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

    Livewire::test('pages::customers')
        ->call('create')
        ->set('name', 'Agroquímicos del Bajío SA de CV')
        ->set('rfc', 'ABC010101AB1')
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

test('creating a customer requires the fiscal fields', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::customers')
        ->call('create')
        ->set('name', '')
        ->set('rfc', '')
        ->set('tax_system', '')
        ->set('cfdi_use', '')
        ->set('zip_code', '')
        ->call('save')
        ->assertHasErrors(['name', 'rfc', 'tax_system', 'cfdi_use', 'zip_code']);
});

test('a customer can be updated', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create(['name' => 'Original']);

    Livewire::test('pages::customers')
        ->call('edit', $customer->id)
        ->set('name', 'Updated name')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->name)->toBe('Updated name');
});

test('a customer can be deleted', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create();

    Livewire::test('pages::customers')
        ->call('confirmDelete', $customer->id)
        ->call('delete');

    expect(Customer::find($customer->id))->toBeNull();
});

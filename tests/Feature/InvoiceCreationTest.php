<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('invoices.create'))->assertRedirect(route('login'));
});

test('an invoice can be created and stamped', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create(['facturapi_id' => 'cus_existing']);
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16]);

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->once()->andReturn([
            'id' => 'inv_test123',
            'series' => 'A',
            'folio_number' => 1,
        ]);
    });

    $component = Livewire::test('pages::invoices.create')
        ->set('customer_id', $customer->id)
        ->set('payment_form', '01')
        ->set('payment_method', 'PUE')
        ->set('cfdi_use', 'G03');

    $rowId = array_key_first($component->get('items'));

    $component
        ->set("items.{$rowId}.product_id", $product->id)
        ->set("items.{$rowId}.quantity", '2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('invoices.index'));

    $invoice = Invoice::first();

    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe(InvoiceStatus::Stamped);
    expect($invoice->facturapi_id)->toBe('inv_test123');
    expect((float) $invoice->subtotal)->toBe(200.0);
    expect((float) $invoice->tax_total)->toBe(32.0);
    expect((float) $invoice->total)->toBe(232.0);
    expect($invoice->items)->toHaveCount(1);
});

test('searching for a customer fills the field with the selection', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create(['name' => 'Agropecuaria Buscable', 'rfc' => 'ABC010101AB1']);

    Livewire::test('pages::invoices.create')
        ->set('customerSearch', 'Buscable')
        ->assertSee('Agropecuaria Buscable')
        ->call('selectCustomer', $customer->id)
        ->assertSet('customer_id', $customer->id)
        ->assertSet('customerSearch', 'Agropecuaria Buscable (ABC010101AB1)')
        ->assertSet('customerResults', []);
});

test('selecting a customer fills in their default cfdi use', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create(['cfdi_use' => 'G01']);

    Livewire::test('pages::invoices.create')
        ->call('selectCustomer', $customer->id)
        ->assertSet('cfdi_use', 'G01');
});

test('typing in a row product field searches and selecting fills that row', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['name' => 'Fertilizante Fila', 'sku' => 'ROW-1']);

    $component = Livewire::test('pages::invoices.create');
    $rowId = array_key_first($component->get('items'));

    $component
        ->set("items.{$rowId}.search", 'Fertilizante Fila')
        ->assertSee('Fertilizante Fila')
        ->call('selectItemProduct', $rowId, $product->id)
        ->assertSet("items.{$rowId}.product_id", $product->id)
        ->assertSet("items.{$rowId}.search", 'Fertilizante Fila (ROW-1)')
        ->assertSet("items.{$rowId}.results", []);
});

test('focusing the customer field without typing shows a browsable list', function () {
    $this->actingAs(User::factory()->create());

    Customer::factory()->create(['name' => 'Cliente Uno']);
    Customer::factory()->create(['name' => 'Cliente Dos']);

    Livewire::test('pages::invoices.create')
        ->call('openCustomerResults')
        ->assertSee('Cliente Uno')
        ->assertSee('Cliente Dos');
});

test('focusing a row product field without typing shows a browsable list', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->create(['name' => 'Producto Uno']);
    Product::factory()->create(['name' => 'Producto Dos']);

    $component = Livewire::test('pages::invoices.create');
    $rowId = array_key_first($component->get('items'));

    $component
        ->call('openItemResults', $rowId)
        ->assertSee('Producto Uno')
        ->assertSee('Producto Dos');
});

test('scanning a barcode adds the product as a new item, or increments an existing one', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['barcode' => '7501234500001']);

    $component = Livewire::test('pages::invoices.create')
        ->set('barcodeScan', '7501234500001')
        ->call('scanBarcode');

    $rowId = array_key_first($component->get('items'));
    $component->assertSet("items.{$rowId}.product_id", $product->id);
    $component->assertSet("items.{$rowId}.quantity", '1');

    $component
        ->set('barcodeScan', '7501234500001')
        ->call('scanBarcode')
        ->assertSet("items.{$rowId}.quantity", '2');
});

test('products without a price do not show up when searching or browsing', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->withoutPrice()->create(['name' => 'Sin Precio Todavia']);
    Product::factory()->create(['name' => 'Con Precio']);

    $component = Livewire::test('pages::invoices.create');
    $rowId = array_key_first($component->get('items'));

    $component
        ->call('openItemResults', $rowId)
        ->assertSee('Con Precio')
        ->assertDontSee('Sin Precio Todavia');
});

test('scanning the barcode of a product without a price shows an error and adds nothing', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->withoutPrice()->create(['barcode' => '7501234500002']);

    $component = Livewire::test('pages::invoices.create')
        ->set('barcodeScan', '7501234500002')
        ->call('scanBarcode');

    $rowId = array_key_first($component->get('items'));
    $component->assertSet("items.{$rowId}.product_id", null);

    expect($product->fresh())->not->toBeNull();
});

test('submitting an invoice with a priceless product fails validation', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create();
    $product = Product::factory()->withoutPrice()->create();

    $component = Livewire::test('pages::invoices.create')
        ->set('customer_id', $customer->id)
        ->set('payment_form', '01')
        ->set('payment_method', 'PUE')
        ->set('cfdi_use', 'G03');

    $rowId = array_key_first($component->get('items'));

    $component
        ->set("items.{$rowId}.product_id", $product->id)
        ->call('save')
        ->assertHasErrors(["items.{$rowId}.product_id"]);
});

test('scanning an unknown barcode on the invoice form adds nothing', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::invoices.create')
        ->set('barcodeScan', 'unknown-code')
        ->call('scanBarcode');

    $rowId = array_key_first($component->get('items'));
    $component->assertSet("items.{$rowId}.product_id", null);
});

test('creating an invoice requires a customer and at least one item', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::invoices.create');
    $rowId = array_key_first($component->get('items'));

    $component
        ->set('customer_id', null)
        ->set("items.{$rowId}.product_id", null)
        ->call('save')
        ->assertHasErrors(['customer_id', "items.{$rowId}.product_id"]);
});

test('reviewing the invoice validates the form before showing the confirmation', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::invoices.create')
        ->call('reviewInvoice')
        ->assertHasErrors(['customer_id', 'payment_form', 'payment_method', 'cfdi_use']);
});

test('the invoice can be reviewed and then confirmed', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create(['facturapi_id' => 'cus_existing']);
    $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 0.16]);

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'inv_test123']);
    });

    $component = Livewire::test('pages::invoices.create')
        ->set('customer_id', $customer->id)
        ->set('payment_form', '01')
        ->set('payment_method', 'PUE')
        ->set('cfdi_use', 'G03');

    $rowId = array_key_first($component->get('items'));

    $component
        ->set("items.{$rowId}.product_id", $product->id)
        ->call('reviewInvoice')
        ->assertHasNoErrors()
        ->call('save')
        ->assertRedirect(route('invoices.index'));

    expect(Invoice::first()->status)->toBe(InvoiceStatus::Stamped);
});

test('the payment form, method and cfdi use have no default and must be chosen explicitly', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::invoices.create')
        ->assertSet('payment_form', '')
        ->assertSet('payment_method', '')
        ->assertSet('cfdi_use', '')
        ->call('save')
        ->assertHasErrors(['payment_form', 'payment_method', 'cfdi_use']);
});

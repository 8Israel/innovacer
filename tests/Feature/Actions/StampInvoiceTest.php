<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Actions\Facturapi\StampInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceStatus;
use App\Models\Product;
use Facturapi\Exceptions\Facturapi_Exception;

test('it registers the customer with facturapi and stamps the invoice', function () {
    $customer = Customer::factory()->create(['facturapi_id' => null]);
    $product = Product::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
    ]);

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn(['id' => 'cus_test123']);
        $mock->shouldReceive('createInvoice')->once()->andReturn([
            'id' => 'inv_test123',
            'series' => 'A',
            'folio_number' => 1,
        ]);
    });

    $result = app(StampInvoice::class)->handle($invoice);

    expect($result->status)->toBe(InvoiceStatus::Stamped);
    expect($result->facturapi_id)->toBe('inv_test123');
    expect($result->stamped_at)->not->toBeNull();
    expect($customer->fresh()->facturapi_id)->toBe('cus_test123');
});

test('it does not re-register a customer that already has a facturapi id', function () {
    $customer = Customer::factory()->create(['facturapi_id' => 'cus_existing']);
    $product = Product::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
    ]);

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldNotReceive('createCustomer');
        $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'inv_test123']);
    });

    app(StampInvoice::class)->handle($invoice);
});

test('a failed stamping marks the invoice as failed with the error message', function () {
    $customer = Customer::factory()->create(['facturapi_id' => 'cus_existing']);
    $product = Product::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
    ]);

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->once()->andThrow(
            new Facturapi_Exception('Invalid product_key')
        );
    });

    $result = app(StampInvoice::class)->handle($invoice);

    expect($result->status)->toBe(InvoiceStatus::Failed);
    expect($result->error_message)->toBe('Invalid product_key');
    expect($result->facturapi_id)->toBeNull();
});

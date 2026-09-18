<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('invoices.index'))->assertRedirect(route('login'));
});

test('invoices page is displayed', function () {
    $this->actingAs(User::factory()->create());

    Invoice::factory()->for(Customer::factory())->create();

    $this->get(route('invoices.index'))->assertOk();
});

test('the invoice status is shown in spanish, not the raw enum value', function () {
    $this->actingAs(User::factory()->create());

    Invoice::factory()->stamped()->for(Customer::factory())->create();

    Livewire::test('pages::invoices.index')
        ->assertSee('Timbrada')
        ->assertDontSee('stamped');
});

test('a stamped invoice can be canceled', function () {
    $this->actingAs(User::factory()->create());

    $invoice = Invoice::factory()->stamped()->for(Customer::factory())->create();

    $this->mock(FacturapiGateway::class, function ($mock) use ($invoice) {
        $mock->shouldReceive('cancelInvoice')->once()->with($invoice->facturapi_id, '02')->andReturn(['status' => 'canceled']);
    });

    Livewire::test('pages::invoices.index')
        ->call('confirmCancel', $invoice->id)
        ->call('cancel');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Canceled);
    expect($invoice->fresh()->canceled_at)->not->toBeNull();
});

test('pdf download is blocked for a non stamped invoice', function () {
    $this->actingAs(User::factory()->create());

    $invoice = Invoice::factory()->for(Customer::factory())->create();

    $this->get(route('invoices.pdf', $invoice))->assertNotFound();
});

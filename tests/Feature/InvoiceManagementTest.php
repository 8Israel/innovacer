<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('invoices.index'))->assertRedirect(route('login'));
});

test('the issued invoices list shows the sales covered, the method and the status', function () {
    $customer = Customer::factory()->create(['name' => 'Ernesto Castaño']);
    $invoice = Invoice::factory()->stamped()->for($customer)->create(['series' => 'A', 'folio' => '0003']);
    $invoice->forceFill(['total' => 14290])->save();
    $invoice->sales()->attach([
        Sale::factory()->for($customer)->create(['folio' => 'V-001018'])->id,
        Sale::factory()->for($customer)->create(['folio' => 'V-001007'])->id,
    ]);
    Invoice::factory()->for($customer)->create(['status' => InvoiceStatus::Canceled, 'series' => 'A', 'folio' => '0001']);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertSeeInOrder(['Facturas emitidas', 'A-0003', 'Ventas V-001018 y V-001007', 'Ernesto Castaño', 'Una exhibición', '$14,290.00', 'Vigente'])
        ->assertSeeInOrder(['A-0001', 'Cancelada'])
        ->assertDontSee('stamped');
});

test('selecting an invoice shows its folio fiscal and actions', function () {
    $invoice = Invoice::factory()->stamped()->for(Customer::factory())->create(['uuid' => '6F1A2B3C-0000-4000-8000-000000000009']);

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::invoices.index')
        ->call('select', $invoice->id)
        ->assertSee('6F1A2B3C-0000-4000-8000-000000000009')
        ->assertSee('PDF')
        ->assertSee('Cancelar factura (dueño)');
});

test('the owner cancels a stamped invoice with the SAT motive chosen', function () {
    $invoice = Invoice::factory()->stamped()->for(Customer::factory())->create();
    $this->mock(FacturapiGateway::class, function ($mock) use ($invoice) {
        $mock->shouldReceive('cancelInvoice')->once()->with($invoice->facturapi_id, '03', null)->andReturn(['status' => 'canceled']);
    });

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::invoices.index')
        ->call('select', $invoice->id)
        ->call('confirmCancel')
        ->set('cancellation_reason', '01')
        ->call('cancel')
        ->assertHasErrors('substitution_uuid')
        ->set('cancellation_reason', '03')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($invoice->fresh()->only(['status', 'cancellation_reason']))->toBe(['status' => InvoiceStatus::Canceled, 'cancellation_reason' => '03']);
    expect($invoice->fresh()->canceled_at)->not->toBeNull();
});

test('the clerk cannot cancel an invoice', function () {
    $invoice = Invoice::factory()->stamped()->for(Customer::factory())->create();
    $this->mock(FacturapiGateway::class, fn ($mock) => $mock->shouldNotReceive('cancelInvoice'));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::invoices.index')
        ->call('select', $invoice->id)
        ->assertDontSee('Cancelar factura (dueño)')
        ->call('cancel')
        ->assertForbidden();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Stamped);
});

test('a failed invoice can be stamped again', function () {
    $invoice = Invoice::factory()->for(Customer::factory()->state(['facturapi_id' => 'cus_1']))->create(['status' => InvoiceStatus::Failed]);
    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'inv_retry', 'uuid' => '6F1A2B3C-0000-4000-8000-000000000010']);
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::invoices.index')
        ->call('select', $invoice->id)
        ->call('retryStamp');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Stamped);
});

test('pdf download is blocked for a non stamped invoice', function () {
    $this->actingAs(User::factory()->create());

    $invoice = Invoice::factory()->for(Customer::factory())->create();

    $this->get(route('invoices.pdf', $invoice))->assertNotFound();
});

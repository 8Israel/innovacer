<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceStatus;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

/**
 * Invoice A-0002 for $3,200 in installments with a $1,500 cash payment on 9 Oct still without complement.
 */
function invoiceWithPendingComplement(): Invoice
{
    $customer = Customer::factory()->withCredit()->create(['name' => 'Ernesto Castaño', 'facturapi_id' => 'cus_ernesto']);
    $sale = Sale::factory()->for($customer)->create(['total' => 3200]);
    $invoice = Invoice::factory()->stamped()->for($customer)->create(['series' => 'A', 'folio' => '0002', 'payment_method' => 'PPD', 'payment_form' => '99']);
    $invoice->forceFill(['subtotal' => 3200, 'total' => 3200])->save();
    $invoice->sales()->attach($sale);
    $payment = CustomerPayment::factory()->for($customer)->create(['method' => PaymentMethod::Cash, 'amount' => 1500, 'paid_at' => '2026-10-09 10:00']);
    CustomerPaymentAllocation::factory()->for($payment)->for($sale)->create(['amount' => 1500]);

    return $invoice;
}

test('a PPD invoice with a payment shows that its complement is missing and stamps it', function () {
    $invoice = invoiceWithPendingComplement();
    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'inv_p1', 'uuid' => '6F1A2B3C-0000-4000-8000-000000000001', 'series' => 'P', 'folio_number' => 1]);
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::invoices.index')
        ->assertSee('Falta complemento')
        ->call('startComplement', $invoice->id)
        ->assertSeeInOrder(['Complemento de pago', 'Factura A-0002', 'Parcialidad', '1', 'Saldo anterior', '$3,200.00', '$1,500.00', 'Saldo pendiente', '$1,700.00'])
        ->call('stampComplement')
        ->assertHasNoErrors()
        ->assertDontSee('Falta complemento');

    expect(InvoicePayment::query()->sole()->outstanding_balance)->toBe('1700.00');
});

test('an invoice is cancelled with the SAT motive chosen', function () {
    $invoice = Invoice::factory()->stamped()->for(Customer::factory())->create();
    $this->mock(FacturapiGateway::class, function ($mock) use ($invoice) {
        $mock->shouldReceive('cancelInvoice')->once()->with($invoice->facturapi_id, '03', null)->andReturn(['status' => 'canceled']);
    });

    Livewire::actingAs(User::factory()->create())
        ->test('pages::invoices.index')
        ->call('confirmCancel', $invoice->id)
        ->set('cancellation_reason', '01')
        ->call('cancel')
        ->assertHasErrors('substitution_uuid')
        ->set('cancellation_reason', '03')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($invoice->fresh()->only(['status', 'cancellation_reason']))->toBe(['status' => InvoiceStatus::Canceled, 'cancellation_reason' => '03']);
});

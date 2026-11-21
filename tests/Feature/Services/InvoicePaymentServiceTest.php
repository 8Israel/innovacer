<?php

use App\Actions\Facturapi\CancelFacturapiInvoice;
use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceStatus;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Services\InvoicePaymentService;
use Illuminate\Support\Str;

/**
 * Invoice A-0002 of docs/diseno/ComplementoPago.html: Ernesto Castaño, $3,200 paid in installments.
 *
 * @return array{invoice: Invoice, sale: Sale, customer: Customer}
 */
function invoiceA0002(): array
{
    $customer = Customer::factory()->withCredit(50000)->create(['name' => 'Ernesto Castaño', 'facturapi_id' => 'cus_ernesto']);
    $sale = Sale::factory()->for($customer)->create(['total' => 3200]);
    $invoice = Invoice::factory()->stamped()->for($customer)->create(['series' => 'A', 'folio' => '0002', 'payment_method' => 'PPD', 'payment_form' => '99']);
    $invoice->forceFill(['subtotal' => 3200, 'total' => 3200])->save();
    InvoiceItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 3200, 'tax_rate' => 0, 'subtotal' => 3200, 'tax_amount' => 0, 'total' => 3200]);
    $invoice->sales()->attach($sale);

    return compact('invoice', 'sale', 'customer');
}

function abono(Customer $customer, Sale $sale, string $amount, string $paidAt): CustomerPayment
{
    $payment = CustomerPayment::factory()->for($customer)->create(['method' => PaymentMethod::Cash, 'amount' => $amount, 'paid_at' => $paidAt]);
    CustomerPaymentAllocation::factory()->for($payment)->for($sale)->create(['amount' => $amount]);

    return $payment;
}

function fakeComplementStamping(): void
{
    test()->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('createInvoice')->andReturnUsing(fn (array $data) => ['id' => 'inv_'.Str::random(8), 'uuid' => (string) Str::uuid(), 'series' => 'P', 'folio_number' => 1]);
    });
}

test('the complement of the design is installment 1 with a balance of $1,700 left', function () {
    ['invoice' => $invoice, 'sale' => $sale, 'customer' => $customer] = invoiceA0002();
    $payment = abono($customer, $sale, '1500', '2026-10-09 10:00');
    fakeComplementStamping();
    $service = app(InvoicePaymentService::class);

    expect($service->needsComplement($invoice))->toBeTrue();

    $installment = $service->stamp($invoice, $payment);

    expect($installment->only(['installment', 'previous_balance', 'amount_paid', 'outstanding_balance', 'related_invoice_id', 'customer_payment_id']))->toBe([
        'installment' => 1,
        'previous_balance' => '3200.00',
        'amount_paid' => '1500.00',
        'outstanding_balance' => '1700.00',
        'related_invoice_id' => $invoice->id,
        'customer_payment_id' => $payment->id,
    ]);
    expect($installment->invoice->only(['type', 'status']))->toBe(['type' => InvoiceType::Payment, 'status' => InvoiceStatus::Stamped]);
    expect($installment->invoice->uuid)->not->toBeNull();
    expect($service->needsComplement($invoice))->toBeFalse();
});

test('installments are consecutive and start from the last balance', function () {
    ['invoice' => $invoice, 'sale' => $sale, 'customer' => $customer] = invoiceA0002();
    $first = abono($customer, $sale, '1500', '2026-10-09 10:00');
    $second = abono($customer, $sale, '1000', '2026-10-20 10:00');
    fakeComplementStamping();
    $service = app(InvoicePaymentService::class);

    $service->stamp($invoice, $first);

    expect($service->stamp($invoice, $second)->only(['installment', 'previous_balance', 'outstanding_balance']))
        ->toBe(['installment' => 2, 'previous_balance' => '1700.00', 'outstanding_balance' => '700.00']);
});

test('a payment invoice in one go needs no complement', function () {
    ['invoice' => $invoice, 'sale' => $sale, 'customer' => $customer] = invoiceA0002();
    $invoice->update(['payment_method' => 'PUE']);
    abono($customer, $sale, '1500', '2026-10-09 10:00');

    expect(app(InvoicePaymentService::class)->needsComplement($invoice->fresh()))->toBeFalse();
});

test('a canceled complement leaves the payment pending again', function () {
    ['invoice' => $invoice, 'sale' => $sale, 'customer' => $customer] = invoiceA0002();
    $payment = abono($customer, $sale, '1500', '2026-10-09 10:00');
    fakeComplementStamping();
    $service = app(InvoicePaymentService::class);
    $installment = $service->stamp($invoice, $payment);

    $installment->invoice->forceFill(['status' => InvoiceStatus::Canceled])->save();

    expect($service->needsComplement($invoice))->toBeTrue();
    expect($service->prepare($invoice, $payment)['installment'])->toBe(1);
});

test('cancelling records the SAT motive and requires the replacing folio for motive 01', function () {
    ['invoice' => $invoice] = invoiceA0002();
    $replacement = (string) Str::uuid();
    $this->mock(FacturapiGateway::class, function ($mock) use ($invoice, $replacement) {
        $mock->shouldReceive('cancelInvoice')->once()->with($invoice->facturapi_id, '01', $replacement)->andReturn(['status' => 'canceled']);
    });
    $cancel = app(CancelFacturapiInvoice::class);

    expect(fn () => $cancel->handle($invoice, '01'))->toThrow(failsOn('substitution_uuid'));

    $canceled = $cancel->handle($invoice, '01', $replacement);

    expect($canceled->only(['status', 'cancellation_reason', 'substitution_uuid']))->toBe([
        'status' => InvoiceStatus::Canceled,
        'cancellation_reason' => '01',
        'substitution_uuid' => mb_strtoupper($replacement),
    ]);
});

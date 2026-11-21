<?php

namespace App\Services;

use App\Actions\Facturapi\FacturapiGateway;
use App\Actions\Facturapi\SyncFacturapiCustomer;
use App\Concerns\RoundsDecimals;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceStatus;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use Facturapi\Exceptions\Facturapi_Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment complements (CFDI of type P) for invoices paid in installments (PPD),
 * Task 11 and docs/diseno/ComplementoPago.html. What a customer payment paid on an
 * invoice is the part of it applied to the sales that invoice covers. Each paid
 * installment is numbered consecutively per invoice, and its outstanding balance
 * is the previous balance less what was paid.
 */
class InvoicePaymentService
{
    use RoundsDecimals;

    private const MONEY = 2;

    public function __construct(
        private FacturapiGateway $gateway,
        private SyncFacturapiCustomer $syncCustomer,
    ) {}

    /**
     * Customer payments applied to the invoice's sales that still need their complement, oldest first.
     *
     * @return Collection<int, array{payment: CustomerPayment, amount: string}>
     */
    public function pendingComplements(Invoice $invoice): Collection
    {
        if (! $invoice->isPaidInInstallments() || $invoice->status !== InvoiceStatus::Stamped) {
            return collect();
        }

        $covered = $this->stampedInstallments($invoice)->pluck('customer_payment_id');

        return CustomerPaymentAllocation::query()
            ->with('customerPayment')
            ->whereIn('sale_id', $invoice->sales()->select('sales.id'))
            ->whereNotIn('customer_payment_id', $covered)
            ->get()
            ->groupBy('customer_payment_id')
            ->map(fn (Collection $allocations) => [
                'payment' => $allocations->first()->customerPayment,
                'amount' => $allocations->reduce(fn (string $carry, CustomerPaymentAllocation $allocation) => bcadd($carry, $allocation->amount, self::MONEY), '0.00'),
            ])
            ->sortBy(fn (array $row) => [$row['payment']->paid_at->getTimestamp(), $row['payment']->id])
            ->values();
    }

    public function needsComplement(Invoice $invoice): bool
    {
        return $this->pendingComplements($invoice)->isNotEmpty();
    }

    /**
     * The installment a payment would be on the invoice.
     *
     * @return array{installment: int, previous_balance: string, amount_paid: string, outstanding_balance: string}
     */
    public function prepare(Invoice $invoice, CustomerPayment $payment): array
    {
        $pending = $this->pendingComplements($invoice)->first(fn (array $row) => $row['payment']->is($payment))
            ?? throw ValidationException::withMessages(['customer_payment_id' => __('Ese abono no tiene un complemento pendiente en esta factura.')]);

        $installments = $this->stampedInstallments($invoice);
        $previousBalance = $installments->isEmpty()
            ? bcadd($invoice->total, '0', self::MONEY)
            : $installments->sortByDesc('installment')->first()->outstanding_balance;

        if (bccomp($pending['amount'], $previousBalance, self::MONEY) === 1) {
            throw ValidationException::withMessages(['customer_payment_id' => __('El abono ($:amount) es mayor al saldo de la factura ($:balance).', [
                'amount' => number_format((float) $pending['amount'], 2),
                'balance' => number_format((float) $previousBalance, 2),
            ])]);
        }

        return [
            'installment' => ($installments->max('installment') ?? 0) + 1,
            'previous_balance' => $previousBalance,
            'amount_paid' => $pending['amount'],
            'outstanding_balance' => bcsub($previousBalance, $pending['amount'], self::MONEY),
        ];
    }

    /**
     * Stamp the payment complement of one customer payment on the invoice.
     */
    public function stamp(Invoice $invoice, CustomerPayment $payment): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $payment): InvoicePayment {
            $invoice = Invoice::query()->with(['customer', 'items'])->lockForUpdate()->findOrFail($invoice->id);
            $installment = $this->prepare($invoice, $payment);
            $paymentForm = $this->paymentForm($payment->method);

            try {
                $response = $this->gateway->createInvoice([
                    'type' => 'P',
                    'customer' => $this->syncCustomer->handle($invoice->customer),
                    'complements' => [[
                        'type' => 'pago',
                        'data' => [[
                            'payment_form' => $paymentForm,
                            'date' => $payment->paid_at->toIso8601String(),
                            'related_documents' => [[
                                'uuid' => $invoice->uuid,
                                'amount' => (float) $installment['amount_paid'],
                                'installment' => $installment['installment'],
                                'last_balance' => (float) $installment['previous_balance'],
                                'taxes' => $this->taxes($invoice, $installment['amount_paid']),
                            ]],
                        ]],
                    ]],
                ]);
            } catch (Facturapi_Exception $exception) {
                throw ValidationException::withMessages(['complement' => $exception->getMessage()]);
            }

            $complement = new Invoice([
                'customer_id' => $invoice->customer_id,
                'payment_form' => $paymentForm,
                'payment_method' => 'PUE',
                'cfdi_use' => 'CP01',
                'currency' => 'XXX',
            ]);
            $complement->forceFill([
                'type' => InvoiceType::Payment,
                'status' => InvoiceStatus::Stamped,
                'facturapi_id' => $response['id'],
                'uuid' => $response['uuid'] ?? null,
                'series' => $response['series'] ?? null,
                'folio' => isset($response['folio_number']) ? (string) $response['folio_number'] : null,
                'total' => $installment['amount_paid'],
                'stamped_at' => now(),
            ])->save();

            return $complement->complementPayments()->create([
                'related_invoice_id' => $invoice->id,
                'customer_payment_id' => $payment->id,
                ...$installment,
            ]);
        });
    }

    /**
     * Installments already covered by a complement that is still valid.
     *
     * @return Collection<int, InvoicePayment>
     */
    private function stampedInstallments(Invoice $invoice): Collection
    {
        return $invoice->paidInstallments()
            ->whereHas('invoice', fn ($complements) => $complements->where('status', InvoiceStatus::Stamped))
            ->get();
    }

    /**
     * The IVA of the related document, in proportion to what this payment covers.
     *
     * @return list<array{base: float, type: string, rate: float}>
     */
    private function taxes(Invoice $invoice, string $amountPaid): array
    {
        if (bccomp($invoice->total, '0', self::MONEY) !== 1) {
            return [];
        }

        $share = bcdiv($amountPaid, $invoice->total, 8);

        return $invoice->items->groupBy(fn ($item) => (string) $item->tax_rate)
            ->map(fn (Collection $items, string $rate) => [
                'base' => (float) $this->roundHalfUp(bcmul($items->reduce(fn (string $carry, $item) => bcadd($carry, $item->subtotal, self::MONEY), '0'), $share, 8), self::MONEY),
                'type' => 'IVA',
                'rate' => (float) $rate,
            ])
            ->values()
            ->all();
    }

    /**
     * SAT c_FormaPago of the way the customer paid.
     */
    private function paymentForm(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => '01',
            PaymentMethod::Transfer => '03',
            PaymentMethod::Card => '04',
        };
    }
}

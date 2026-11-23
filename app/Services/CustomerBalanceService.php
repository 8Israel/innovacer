<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\CustomerPaymentAllocation;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What customers owe on credit.
 *
 * Pending of a sale = total − sale payments − payment (abono) allocations
 *                     − returns credited to the account.
 * A destination's balance and a customer's balance are the sums of the pending
 * amounts of their completed sales.
 */
class CustomerBalanceService
{
    use RoundsDecimals;

    private const MONEY = 2;

    /**
     * What is still owed on one sale.
     */
    public function pending(Sale $sale): string
    {
        $sale = $this->withSettledAmounts(Sale::query()->whereKey($sale->id))->firstOrFail();

        return $this->pendingOf($sale);
    }

    /**
     * What the customer owes across all their completed sales.
     */
    public function balance(Customer $customer): string
    {
        return $this->sumPending($this->completedSales($customer->sales())->get());
    }

    /**
     * What the customer owes on one destination (account).
     */
    public function destinationBalance(CustomerDestination $destination): string
    {
        return $this->sumPending($this->completedSales($destination->sales())->get());
    }

    /**
     * Completed sales of a customer's account that still owe something, oldest first,
     * each with its "pending" attribute. Without a destination, the sales that have
     * none — or every sale of the customer with $allAccounts.
     *
     * @return Collection<int, Sale>
     */
    public function salesWithBalance(Customer $customer, ?CustomerDestination $destination = null, bool $allAccounts = false): Collection
    {
        return $this->completedSales($customer->sales())
            ->when(! $allAccounts, fn (Builder $query) => $query->when(
                $destination,
                fn (Builder $query) => $query->where('customer_destination_id', $destination->id),
                fn (Builder $query) => $query->whereNull('customer_destination_id'),
            ))
            ->orderBy('sold_at')
            ->orderBy('id')
            ->get()
            ->each(fn (Sale $sale) => $sale->setAttribute('pending', $this->pendingOf($sale)))
            ->filter(fn (Sale $sale) => bccomp($sale->pending, '0', self::MONEY) === 1)
            ->values();
    }

    /**
     * The customer's completed sales that went (at least partly) on credit, newest
     * first, with what was charged on credit, what has been paid since, what is
     * pending and when it was settled.
     *
     * @return Collection<int, array{sale: Sale, on_credit: string, paid: string, pending: string, settled_at: Carbon|null}>
     */
    public function creditSales(Customer $customer): Collection
    {
        return $this->completedSales($customer->sales())
            ->with(['customerDestination', 'paymentAllocations.customerPayment', 'customerReturns'])
            ->orderByDesc('sold_at')
            ->get()
            ->map(function (Sale $sale): array {
                $onCredit = bcsub($sale->total, $this->decimal($sale->getAttribute('payments_sum_amount'), self::MONEY), self::MONEY);
                $pending = $this->pendingOf($sale);
                $settledAt = bccomp($pending, '0', self::MONEY) <= 0
                    ? collect([
                        ...$sale->paymentAllocations->map(fn (CustomerPaymentAllocation $allocation) => $allocation->customerPayment->paid_at),
                        ...$sale->customerReturns->where('refund_method', RefundMethod::AccountCredit)->pluck('returned_at'),
                    ])->max()
                    : null;

                return [
                    'sale' => $sale,
                    'on_credit' => $onCredit,
                    'paid' => bcsub($onCredit, $pending, self::MONEY),
                    'pending' => $pending,
                    'settled_at' => $settledAt,
                ];
            })
            ->filter(fn (array $row) => bccomp($row['on_credit'], '0', self::MONEY) === 1)
            ->values();
    }

    /**
     * The customer's account movements in date order: what each sale left on credit
     * (charge), customer payments and returns credited to the account (payments),
     * with the running balance of their account. Optionally only one account.
     *
     * @return Collection<int, array{date: Carbon, concept: string, account: string|null, charge: string, payment: string, balance: string}>
     */
    public function ledger(Customer $customer, ?CustomerDestination $destination = null): Collection
    {
        $entries = collect();

        foreach ($this->creditSales($customer) as $row) {
            $sale = $row['sale'];
            $account = $sale->customerDestination;

            if ($destination !== null && $account?->id !== $destination->id) {
                continue;
            }

            $entries->push(['date' => $sale->sold_at, 'order' => 0, 'sequence' => $sale->id, 'concept' => __('Venta :folio', ['folio' => $sale->folio]), 'account' => $account, 'charge' => $row['on_credit'], 'payment' => '0.00']);

            foreach ($sale->paymentAllocations->groupBy('customer_payment_id') as $allocations) {
                $payment = $allocations->first()->customerPayment;
                $entries->push([
                    'date' => $payment->paid_at,
                    'order' => 1,
                    'sequence' => $allocations->min('id'),
                    'concept' => collect([
                        __('Abono · :method', ['method' => mb_strtolower($payment->method->label())]),
                        __('aplicado a :folio', ['folio' => $sale->folio]),
                        $payment->reference ? __('ref. :reference', ['reference' => $payment->reference]) : null,
                    ])->filter()->join(' · '),
                    'account' => $account,
                    'charge' => '0.00',
                    'payment' => $allocations->reduce(fn (string $carry, CustomerPaymentAllocation $allocation) => bcadd($carry, $allocation->amount, self::MONEY), '0.00'),
                ]);
            }

            foreach ($sale->customerReturns->where('refund_method', RefundMethod::AccountCredit) as $return) {
                $entries->push(['date' => $return->returned_at, 'order' => 1, 'sequence' => $return->id, 'concept' => __('Devolución :folio', ['folio' => $return->folio]), 'account' => $account, 'charge' => '0.00', 'payment' => $return->refund_amount]);
            }
        }

        $balances = [];

        return $entries
            ->sortBy([['date', 'asc'], ['order', 'asc'], ['sequence', 'asc']])
            ->values()
            ->map(function (array $entry) use (&$balances): array {
                $key = $entry['account']?->id ?? 0;
                $balances[$key] = bcsub(bcadd($balances[$key] ?? '0', $entry['charge'], self::MONEY), $entry['payment'], self::MONEY);

                return [
                    'date' => $entry['date'],
                    'concept' => $entry['concept'],
                    'account' => $entry['account']?->name,
                    'charge' => $entry['charge'],
                    'payment' => $entry['payment'],
                    'balance' => $balances[$key],
                ];
            });
    }

    /**
     * @param  HasMany<Sale, *>  $sales
     * @return Builder<Sale>
     */
    private function completedSales(HasMany $sales): Builder
    {
        return $this->withSettledAmounts($sales->getQuery()->where('status', SaleStatus::Completed));
    }

    /**
     * @param  Builder<Sale>  $query
     * @return Builder<Sale>
     */
    private function withSettledAmounts(Builder $query): Builder
    {
        return $query
            ->withSum('payments', 'amount')
            ->withSum('paymentAllocations', 'amount')
            ->withSum(['customerReturns as credited_returns_sum' => fn (Builder $returns) => $returns->where('refund_method', RefundMethod::AccountCredit)], 'refund_amount');
    }

    private function pendingOf(Sale $sale): string
    {
        $settled = [
            $sale->getAttribute('payments_sum_amount'),
            $sale->getAttribute('payment_allocations_sum_amount'),
            $sale->getAttribute('credited_returns_sum'),
        ];

        return array_reduce(
            $settled,
            fn (string $carry, mixed $amount) => bcsub($carry, $this->decimal($amount, self::MONEY), self::MONEY),
            $sale->total,
        );
    }

    /**
     * @param  Collection<int, Sale>  $sales
     */
    private function sumPending(Collection $sales): string
    {
        return $sales->reduce(fn (string $carry, Sale $sale) => bcadd($carry, $this->pendingOf($sale), self::MONEY), '0.00');
    }
}

<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

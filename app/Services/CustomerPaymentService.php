<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers customer payments (abonos) and spreads them over the account's sales.
 */
class CustomerPaymentService
{
    private const MONEY = 2;

    public function __construct(
        private CashSessionService $cashSessions,
        private CustomerBalanceService $balances,
    ) {}

    /**
     * Register a payment to one of the customer's accounts in the open register.
     * It settles the oldest sale with a balance first (or the chosen one), and what
     * is left goes to the next sale of the same account. It may not exceed what the
     * account owes.
     */
    public function register(
        User $user,
        Customer $customer,
        ?CustomerDestination $destination,
        PaymentMethod $method,
        string $amount,
        ?string $reference = null,
        ?string $note = null,
        ?Sale $startAt = null,
    ): CustomerPayment {
        if (! is_numeric($amount) || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('El abono debe ser mayor a cero.')]);
        }

        $amount = bcadd($amount, '0', self::MONEY);

        if ($destination !== null && $destination->customer_id !== $customer->id) {
            throw ValidationException::withMessages([
                'customer_destination_id' => __('La cuenta no pertenece a :customer.', ['customer' => $customer->name]),
            ]);
        }

        if ($destination === null && $customer->destinations()->exists()) {
            throw ValidationException::withMessages([
                'customer_destination_id' => __('Elige la cuenta a la que se aplica el abono.'),
            ]);
        }

        return DB::transaction(function () use ($user, $customer, $destination, $method, $amount, $reference, $note, $startAt): CustomerPayment {
            $session = $this->cashSessions->requireOpen();

            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->sales()->lockForUpdate()->pluck('id');

            $sales = $this->balances->salesWithBalance($customer, $destination);

            if ($startAt !== null) {
                $chosen = $sales->firstWhere('id', $startAt->id)
                    ?? throw ValidationException::withMessages(['sale_id' => __('Esa venta no tiene saldo en esta cuenta.')]);

                $sales = $sales->reject(fn (Sale $sale) => $sale->is($chosen))->prepend($chosen);
            }

            $owed = $sales->reduce(fn (string $carry, Sale $sale) => bcadd($carry, $sale->pending, self::MONEY), '0.00');

            if (bccomp($amount, $owed, self::MONEY) === 1) {
                throw ValidationException::withMessages([
                    'amount' => __('La cuenta :account debe $:owed; el abono no puede ser mayor.', [
                        'account' => $destination?->name ?? $customer->name,
                        'owed' => $owed,
                    ]),
                ]);
            }

            $payment = CustomerPayment::create([
                'cash_session_id' => $session->id,
                'customer_id' => $customer->id,
                'customer_destination_id' => $destination?->id,
                'user_id' => $user->id,
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'note' => $note,
                'paid_at' => now(),
            ]);

            $remaining = $amount;

            foreach ($sales as $sale) {
                if (bccomp($remaining, '0', self::MONEY) <= 0) {
                    break;
                }

                $applied = bccomp($sale->pending, $remaining, self::MONEY) < 0 ? $sale->pending : $remaining;
                $payment->allocations()->create(['sale_id' => $sale->id, 'amount' => $applied]);
                $remaining = bcsub($remaining, $applied, self::MONEY);
            }

            return $payment;
        });
    }
}

<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\PaymentMethod;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens and closes the single cash register and computes the cash it should hold.
 * Money is handled as strings with bcmath at 2 decimals to avoid float rounding.
 */
class CashSessionService
{
    private const SCALE = 2;

    /**
     * Open the register. Fails if another session is open, or if the opening cash
     * differs from what the previous closing left and no note explains it.
     */
    public function open(User $user, string $openingCash, ?string $note = null): CashSession
    {
        $openingCash = $this->amount($openingCash, 'opening_cash');

        return DB::transaction(function () use ($user, $openingCash, $note): CashSession {
            if ($this->openQuery()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'cash_session' => __('Ya hay una caja abierta. Ciérrala antes de abrir otra.'),
                ]);
            }

            $previous = CashSession::query()
                ->where('status', CashSessionStatus::Closed)
                ->latest('closed_at')
                ->latest('id')
                ->first();

            $differsFromPrevious = $previous?->cash_left !== null
                && bccomp($openingCash, $previous->cash_left, self::SCALE) !== 0;

            if ($differsFromPrevious && blank($note)) {
                throw ValidationException::withMessages([
                    'opening_note' => __('El fondo no coincide con los $:expected que dejó el corte anterior. Escribe una nota que lo explique.', [
                        'expected' => $previous->cash_left,
                    ]),
                ]);
            }

            return CashSession::create([
                'opened_by_id' => $user->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'opening_note' => $note,
                'status' => CashSessionStatus::Open,
                'open_lock' => 1,
            ]);
        });
    }

    /**
     * The currently open session, if any.
     */
    public function current(): ?CashSession
    {
        return $this->openQuery()->first();
    }

    /**
     * The open session that sales, customer payments and cash movements must use.
     *
     * @throws ValidationException when the register is closed.
     */
    public function requireOpen(): CashSession
    {
        return $this->current() ?? throw $this->registerClosedException();
    }

    /**
     * Record cash entering or leaving the open register. Outflows require a beneficiary.
     */
    public function recordMovement(
        User $user,
        CashMovementType $type,
        string $concept,
        string $amount,
        ?string $beneficiary = null,
        ?string $reference = null,
        ?string $note = null,
    ): CashMovement {
        $amount = $this->amount($amount, 'amount', allowZero: false);

        if ($type === CashMovementType::Outflow && blank($beneficiary)) {
            throw ValidationException::withMessages([
                'beneficiary' => __('Indica a quién se le entregó el dinero.'),
            ]);
        }

        return DB::transaction(function () use ($user, $type, $concept, $amount, $beneficiary, $reference, $note): CashMovement {
            $session = $this->openQuery()->lockForUpdate()->first() ?? throw $this->registerClosedException();

            return $session->cashMovements()->create([
                'user_id' => $user->id,
                'type' => $type,
                'concept' => $concept,
                'beneficiary' => $beneficiary,
                'amount' => $amount,
                'reference' => $reference,
                'note' => $note,
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * Cash the register should hold: opening cash + cash sale payments + cash customer
     * payments + inflows − outflows − cash refunds − cash supplier payments.
     */
    public function expectedCash(CashSession $session): string
    {
        $cash = PaymentMethod::Cash;

        $additions = [
            $session->opening_cash,
            $this->sum($session->salePayments()->where('method', $cash)),
            $this->sum($session->customerPayments()->where('method', $cash)),
            $this->sum($session->cashMovements()->where('type', CashMovementType::Inflow)),
        ];
        $subtractions = [
            $this->sum($session->cashMovements()->where('type', CashMovementType::Outflow)),
            $this->sum($session->customerReturns()->where('refund_method', RefundMethod::Cash), 'refund_amount'),
            $this->sum($session->supplierPayments()->where('method', $cash)),
        ];

        $expected = array_reduce($additions, fn (string $carry, string $value) => bcadd($carry, $value, self::SCALE), '0');

        return array_reduce($subtractions, fn (string $carry, string $value) => bcsub($carry, $value, self::SCALE), $expected);
    }

    /**
     * Close the open register. A difference between counted and expected cash requires a note.
     */
    public function close(User $user, string $countedCash, string $cashLeft, ?string $note = null): CashSession
    {
        $countedCash = $this->amount($countedCash, 'counted_cash');
        $cashLeft = $this->amount($cashLeft, 'cash_left');

        if (bccomp($cashLeft, $countedCash, self::SCALE) === 1) {
            throw ValidationException::withMessages([
                'cash_left' => __('El fondo que se deja no puede ser mayor al efectivo contado.'),
            ]);
        }

        return DB::transaction(function () use ($user, $countedCash, $cashLeft, $note): CashSession {
            $session = $this->openQuery()->lockForUpdate()->first() ?? throw $this->registerClosedException();

            $expectedCash = $this->expectedCash($session);
            $difference = bcsub($countedCash, $expectedCash, self::SCALE);

            if (bccomp($difference, '0', self::SCALE) !== 0 && blank($note)) {
                throw ValidationException::withMessages([
                    'closing_note' => __('Hay una diferencia de $:difference contra lo esperado. Escribe una nota que la explique.', [
                        'difference' => $difference,
                    ]),
                ]);
            }

            $session->update([
                'closed_by_id' => $user->id,
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'cash_difference' => $difference,
                'cash_left' => $cashLeft,
                'card_total' => $this->methodTotal($session, PaymentMethod::Card),
                'transfer_total' => $this->methodTotal($session, PaymentMethod::Transfer),
                'credit_total' => $this->creditTotal($session),
                'closing_note' => $note,
                'status' => CashSessionStatus::Closed,
                'open_lock' => null,
            ]);

            return $session;
        });
    }

    /**
     * Collected with a payment method in the session, from sales and customer payments.
     */
    private function methodTotal(CashSession $session, PaymentMethod $method): string
    {
        return bcadd(
            $this->sum($session->salePayments()->where('method', $method)),
            $this->sum($session->customerPayments()->where('method', $method)),
            self::SCALE,
        );
    }

    /**
     * What the session's completed sales left unpaid: total − sale payments.
     */
    private function creditTotal(CashSession $session): string
    {
        return $session->sales()
            ->where('status', SaleStatus::Completed)
            ->withSum('payments', 'amount')
            ->get(['id', 'total'])
            ->reduce(fn (string $carry, Sale $sale) => bcadd(
                $carry,
                bcsub($sale->total, $this->normalize($sale->payments_sum_amount), self::SCALE),
                self::SCALE,
            ), '0.00');
    }

    /**
     * @return Builder<CashSession>
     */
    private function openQuery(): Builder
    {
        return CashSession::query()->where('status', CashSessionStatus::Open);
    }

    private function sum(HasMany $query, string $column = 'amount'): string
    {
        return $this->normalize($query->sum($column));
    }

    /**
     * Turn a database aggregate (string, int, float or null) into a 2-decimal string.
     */
    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return is_float($value) ? sprintf('%.2f', $value) : bcadd((string) $value, '0', self::SCALE);
    }

    /**
     * Validate a money amount and normalize it to 2 decimals.
     */
    private function amount(string $value, string $field, bool $allowZero = true): string
    {
        $isValid = is_numeric($value) && ($allowZero ? $value >= 0 : $value > 0);

        if (! $isValid) {
            throw ValidationException::withMessages([
                $field => $allowZero
                    ? __('La cantidad debe ser un número mayor o igual a cero.')
                    : __('La cantidad debe ser un número mayor a cero.'),
            ]);
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function registerClosedException(): ValidationException
    {
        return ValidationException::withMessages([
            'cash_session' => __('No hay caja abierta. Abre la caja para registrar movimientos de dinero.'),
        ]);
    }
}

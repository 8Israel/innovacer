<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierSettlementType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money paid toward what is owed to a supplier. A cash payment leaves the open
 * register through this record only — it is never also a cash movement, so it is
 * subtracted from the expected cash exactly once. Expenses with a supplier that are
 * not debt (e.g. freight) are cash movements instead (CashMovementCategory::SupplierExpense).
 * Each payment is spread over the pending lots (RF-31, see SupplierDebtService::plan()).
 */
class SupplierPaymentService
{
    public function __construct(
        private CashSessionService $cashSessions,
        private SupplierDebtService $debts,
    ) {}

    /**
     * @param  array<int, string>|null  $productAmounts  how much goes to each product; null settles the oldest debt first.
     */
    public function pay(
        User $user,
        Supplier $supplier,
        PaymentMethod $method,
        string $amount,
        ?string $reference = null,
        ?string $note = null,
        ?array $productAmounts = null,
    ): SupplierPayment {
        if (! $user->hasPermission(Permission::PaySuppliers)) {
            throw ValidationException::withMessages(['authorization' => __('Solo el dueño puede pagar a proveedores.')]);
        }

        if ($supplier->settlement_type === SupplierSettlementType::InKind) {
            throw ValidationException::withMessages([
                'supplier_id' => __('A :supplier se le repone producto; regístralo en Préstamos.', ['supplier' => $supplier->name]),
            ]);
        }

        if (! is_numeric($amount) || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('El pago debe ser mayor a cero.')]);
        }

        return DB::transaction(function () use ($user, $supplier, $method, $amount, $reference, $note, $productAmounts): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $amount = bcadd($amount, '0', 2);

            $session = $method === PaymentMethod::Cash
                ? $this->cashSessions->requireOpen()
                : $this->cashSessions->current();

            $plan = $this->debts->plan($supplier, $amount, $productAmounts);

            $payment = SupplierPayment::create([
                'cash_session_id' => $session?->id,
                'supplier_id' => $supplier->id,
                'user_id' => $user->id,
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'note' => $note,
                'paid_at' => now(),
            ]);

            foreach ($plan as $allocation) {
                $payment->allocations()->create([
                    'lot_id' => $allocation['lot']->id,
                    'quantity' => $allocation['quantity'],
                    'amount' => $allocation['amount'],
                ]);
            }

            return $payment;
        });
    }
}

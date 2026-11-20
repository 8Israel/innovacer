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
 * Spreading a payment over lots (RF-31) comes with the suppliers module.
 */
class SupplierPaymentService
{
    public function __construct(private CashSessionService $cashSessions) {}

    public function pay(User $user, Supplier $supplier, PaymentMethod $method, string $amount, ?string $reference = null, ?string $note = null): SupplierPayment
    {
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

        return DB::transaction(function () use ($user, $supplier, $method, $amount, $reference, $note): SupplierPayment {
            $session = $method === PaymentMethod::Cash
                ? $this->cashSessions->requireOpen()
                : $this->cashSessions->current();

            return SupplierPayment::create([
                'cash_session_id' => $session?->id,
                'supplier_id' => $supplier->id,
                'user_id' => $user->id,
                'method' => $method,
                'amount' => bcadd($amount, '0', 2),
                'reference' => $reference,
                'note' => $note,
                'paid_at' => now(),
            ]);
        });
    }
}

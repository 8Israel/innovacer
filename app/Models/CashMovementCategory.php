<?php

namespace App\Models;

/**
 * What cash entering or leaving the register was for. Paying what is owed to a
 * supplier is not a cash movement: it is a supplier payment (SupplierPaymentService).
 */
enum CashMovementCategory: string
{
    /** An expense with a supplier that is not a debt payment, e.g. Coda's freight. */
    case SupplierExpense = 'supplier_expense';

    case Expense = 'expense';

    case OwnerWithdrawal = 'owner_withdrawal';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SupplierExpense => __('Pago a proveedor'),
            self::Expense => __('Gasto'),
            self::OwnerWithdrawal => __('Retiro del dueño'),
            self::Other => __('Otro'),
        };
    }
}

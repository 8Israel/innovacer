<?php

namespace App\Models;

/**
 * Actions guarded by fixed, per-role permissions (see UserRole::allows()).
 * Each case is registered as a Gate with its value as the ability name.
 */
enum Permission: string
{
    case Sell = 'sell';
    case ManageInventory = 'manage_inventory';
    case RegisterPayments = 'register_payments';
    case RegisterReturns = 'register_returns';
    case Invoice = 'invoice';
    case ChangePrices = 'change_prices';
    case AuthorizeDiscounts = 'authorize_discounts';
    case CancelSales = 'cancel_sales';
    case PaySuppliers = 'pay_suppliers';
    case ViewReports = 'view_reports';
    case ManageSettings = 'manage_settings';
}

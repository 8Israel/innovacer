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

    public function label(): string
    {
        return match ($this) {
            self::Sell => __('Vender y cobrar'),
            self::ManageInventory => __('Registrar entradas, traspasos y ajustes'),
            self::RegisterPayments => __('Registrar abonos de clientes'),
            self::RegisterReturns => __('Registrar devoluciones'),
            self::Invoice => __('Facturar'),
            self::ChangePrices => __('Cambiar precios y descuentos de clientes'),
            self::AuthorizeDiscounts => __('Autorizar descuentos en una venta'),
            self::CancelSales => __('Cancelar ventas'),
            self::PaySuppliers => __('Pagar a proveedores'),
            self::ViewReports => __('Ver reportes y utilidad'),
            self::ManageSettings => __('Entrar a Configuración'),
        };
    }
}

<?php

namespace App\Models;

enum UserRole: string
{
    case Owner = 'owner';
    case Clerk = 'clerk';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Dueño'),
            self::Clerk => __('Encargada'),
        };
    }

    /**
     * Permissions are fixed per role: the owner can do everything; the clerk
     * cannot cancel sales, pay suppliers, see reports or change settings.
     * Both may change prices and authorize discounts (decided 2026-11-19).
     */
    public function allows(Permission $permission): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Clerk => ! in_array($permission, [
                Permission::CancelSales,
                Permission::PaySuppliers,
                Permission::ViewReports,
                Permission::ManageSettings,
            ], true),
        };
    }
}

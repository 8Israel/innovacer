<?php

use App\Models\Permission;
use App\Models\UserRole;

test('the owner can do everything', function (Permission $permission) {
    expect(UserRole::Owner->allows($permission))->toBeTrue();
})->with(Permission::cases());

test('the clerk can do the daily work', function (Permission $permission) {
    expect(UserRole::Clerk->allows($permission))->toBeTrue();
})->with([
    Permission::Sell,
    Permission::ManageInventory,
    Permission::RegisterPayments,
    Permission::RegisterReturns,
    Permission::Invoice,
    Permission::ChangePrices,
    Permission::AuthorizeDiscounts,
]);

test('the clerk cannot cancel sales, pay suppliers, see reports or change settings', function (Permission $permission) {
    expect(UserRole::Clerk->allows($permission))->toBeFalse();
})->with([
    Permission::CancelSales,
    Permission::PaySuppliers,
    Permission::ViewReports,
    Permission::ManageSettings,
]);

<?php

use App\Models\CashMovement;
use App\Models\CashMovementCategory;
use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\Lot;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\SupplierPaymentService;

test('it takes a cash debt payment to Ortitec out of the expected cash exactly once', function () {
    $session = CashSession::factory()->create(['opening_cash' => 10000]);
    $ortitec = Supplier::factory()->create(['name' => 'Ortitec']);
    Lot::factory()->for($ortitec)->create(['quantity_received' => 10, 'unit_cost' => 1000]);

    $payment = app(SupplierPaymentService::class)->pay(User::factory()->owner()->create(), $ortitec, PaymentMethod::Cash, '4000', 'Recibo 120');

    expect($payment->cash_session_id)->toBe($session->id);
    expect(CashMovement::count())->toBe(0);
    expect(app(CashSessionService::class)->expectedCash($session))->toBe('6000.00');
});

test('it records Coda freight as a cash outflow that is not a payment of the debt', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    $coda = Supplier::factory()->create(['name' => 'Coda']);

    $movement = app(CashSessionService::class)->recordMovement(
        User::factory()->create(),
        CashMovementType::Outflow,
        'Flete, recibo 311',
        '780',
        category: CashMovementCategory::SupplierExpense,
        supplier: $coda,
    );

    expect($movement->only(['category', 'supplier_id', 'beneficiary']))->toBe([
        'category' => CashMovementCategory::SupplierExpense,
        'supplier_id' => $coda->id,
        'beneficiary' => 'Coda',
    ]);
    expect($session->cashMovements()->where('type', CashMovementType::Outflow)->count())->toBe(1);
    expect(SupplierPayment::query()->where('supplier_id', $coda->id)->exists())->toBeFalse();
    expect(app(CashSessionService::class)->expectedCash($session))->toBe('220.00');
});

test('it requires the supplier of a supplier expense and only of it', function (CashMovementCategory $category, bool $withSupplier) {
    CashSession::factory()->create();

    expect(fn () => app(CashSessionService::class)->recordMovement(
        User::factory()->create(),
        CashMovementType::Outflow,
        'Flete',
        '780',
        'Chofer',
        category: $category,
        supplier: $withSupplier ? Supplier::factory()->create() : null,
    ))->toThrow(failsOn('supplier_id'));
    expect(CashMovement::count())->toBe(0);
})->with([
    'supplier expense without supplier' => [CashMovementCategory::SupplierExpense, false],
    'expense with a supplier' => [CashMovementCategory::Expense, true],
]);

test('it forbids the clerk to pay suppliers', function () {
    CashSession::factory()->create();

    expect(fn () => app(SupplierPaymentService::class)->pay(User::factory()->create(), Supplier::factory()->create(), PaymentMethod::Cash, '100'))
        ->toThrow(failsOn('authorization'));
    expect(SupplierPayment::count())->toBe(0);
});

test('it refuses a cash payment to a supplier without an open register', function () {
    expect(fn () => app(SupplierPaymentService::class)->pay(User::factory()->owner()->create(), Supplier::factory()->create(), PaymentMethod::Cash, '100'))
        ->toThrow(failsOn('cash_session'));
    expect(SupplierPayment::count())->toBe(0);
});

test('it refuses to pay a supplier who is repaid in kind', function () {
    CashSession::factory()->create();

    expect(fn () => app(SupplierPaymentService::class)->pay(User::factory()->owner()->create(), Supplier::factory()->inKind()->create(), PaymentMethod::Transfer, '100'))
        ->toThrow(failsOn('supplier_id'));
});

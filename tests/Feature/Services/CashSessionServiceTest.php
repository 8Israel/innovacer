<?php

use App\Models\CashMovement;
use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\PaymentMethod;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\CashSessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Expects a validation error on the given field.
 */
function failsOn(string $field): Closure
{
    return fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field);
}

test('it opens the register with the opening cash', function () {
    $user = User::factory()->create();

    $session = app(CashSessionService::class)->open($user, '1000');

    expect($session->status)->toBe(CashSessionStatus::Open);
    expect($session->opening_cash)->toBe('1000.00');
    expect($session->openedBy->is($user))->toBeTrue();
    expect(app(CashSessionService::class)->current()->is($session))->toBeTrue();
});

test('it rejects opening a second register while one is open', function () {
    CashSession::factory()->create();

    expect(fn () => app(CashSessionService::class)->open(User::factory()->create(), '1000'))
        ->toThrow(failsOn('cash_session'));
    expect(CashSession::count())->toBe(1);
});

test('it rejects a second open session at the database level', function () {
    CashSession::factory()->create();

    expect(fn () => CashSession::factory()->create())->toThrow(UniqueConstraintViolationException::class);
});

test('it allows a new session once the previous one is closed', function () {
    CashSession::factory()->closed('500.00')->create();

    $session = app(CashSessionService::class)->open(User::factory()->create(), '500');

    expect($session->isOpen())->toBeTrue();
});

test('it requires a note when the opening cash differs from what the last closing left', function () {
    CashSession::factory()->closed('500.00')->create();

    expect(fn () => app(CashSessionService::class)->open(User::factory()->create(), '800'))
        ->toThrow(failsOn('opening_note'));
    expect(CashSession::count())->toBe(1);
});

test('it opens with a different opening cash when a note explains it', function () {
    CashSession::factory()->closed('500.00')->create();

    $session = app(CashSessionService::class)->open(User::factory()->create(), '800', 'El dueño agregó $300 de cambio');

    expect($session->opening_cash)->toBe('800.00');
    expect($session->opening_note)->toBe('El dueño agregó $300 de cambio');
});

test('it blocks money movements when no register is open', function () {
    $service = app(CashSessionService::class);

    expect(fn () => $service->requireOpen())->toThrow(failsOn('cash_session'));
    expect(fn () => $service->recordMovement(User::factory()->create(), CashMovementType::Outflow, 'Flete', '780', 'Coda'))
        ->toThrow(failsOn('cash_session'));
    expect(CashMovement::count())->toBe(0);
});

test('it records a cash outflow in the open register', function () {
    $session = CashSession::factory()->create();
    $user = User::factory()->create();

    $movement = app(CashSessionService::class)->recordMovement($user, CashMovementType::Outflow, 'Flete', '780', 'Coda');

    expect($movement->cashSession->is($session))->toBeTrue();
    expect($movement->amount)->toBe('780.00');
    expect($movement->user->is($user))->toBeTrue();
});

test('it requires a beneficiary for cash outflows', function () {
    CashSession::factory()->create();

    expect(fn () => app(CashSessionService::class)->recordMovement(User::factory()->create(), CashMovementType::Outflow, 'Flete', '780'))
        ->toThrow(failsOn('beneficiary'));
    expect(CashMovement::count())->toBe(0);
});

test('it computes the expected cash of the design example', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    SalePayment::factory()->for($session)->create(['method' => PaymentMethod::Cash, 'amount' => 10000, 'received_amount' => 10000, 'change_amount' => 0]);
    SalePayment::factory()->for($session)->create(['method' => PaymentMethod::Cash, 'amount' => 4230, 'received_amount' => 4500, 'change_amount' => 270]);
    SalePayment::factory()->for($session)->create(['method' => PaymentMethod::Card, 'amount' => 2000, 'received_amount' => null, 'change_amount' => null]);
    SalePayment::factory()->for(CashSession::factory()->closed())->create(['method' => PaymentMethod::Cash, 'amount' => 999]);
    CustomerPayment::factory()->for($session)->create(['method' => PaymentMethod::Cash, 'amount' => 1500]);
    CashMovement::factory()->for($session)->create(['type' => CashMovementType::Outflow, 'amount' => 1180]);

    $expected = app(CashSessionService::class)->expectedCash($session);

    expect($expected)->toBe('15550.00');
});

test('it adds cash inflows and subtracts cash refunds and cash supplier payments', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    CashMovement::factory()->for($session)->create(['type' => CashMovementType::Inflow, 'amount' => 200]);
    CustomerReturn::factory()->for($session)->create(['refund_method' => RefundMethod::Cash, 'refund_amount' => 300]);
    CustomerReturn::factory()->for($session)->create(['refund_method' => RefundMethod::AccountCredit, 'refund_amount' => 50]);
    SupplierPayment::factory()->for($session)->create(['method' => PaymentMethod::Cash, 'amount' => 400]);
    SupplierPayment::factory()->for($session)->create(['method' => PaymentMethod::Transfer, 'amount' => 5000]);

    $expected = app(CashSessionService::class)->expectedCash($session);

    expect($expected)->toBe('500.00');
});

test('it closes the register with totals by payment method and credit', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    $owner = User::factory()->owner()->create();
    $creditSale = Sale::factory()->for($session)->create(['total' => 1160]);
    SalePayment::factory()->for($session)->for($creditSale)->create(['method' => PaymentMethod::Card, 'amount' => 500]);
    SalePayment::factory()->for($session)->create(['method' => PaymentMethod::Transfer, 'amount' => 300]);
    CustomerPayment::factory()->for($session)->create(['method' => PaymentMethod::Card, 'amount' => 100]);
    CustomerPayment::factory()->for($session)->create(['method' => PaymentMethod::Transfer, 'amount' => 700]);

    $closed = app(CashSessionService::class)->close($owner, '1000', '1000');

    expect($closed->status)->toBe(CashSessionStatus::Closed);
    expect($closed->open_lock)->toBeNull();
    expect($closed->closedBy->is($owner))->toBeTrue();
    expect($closed->expected_cash)->toBe('1000.00');
    expect($closed->cash_difference)->toBe('0.00');
    expect($closed->cash_left)->toBe('1000.00');
    expect($closed->card_total)->toBe('600.00');
    expect($closed->transfer_total)->toBe('1000.00');
    expect($closed->credit_total)->toBe('660.00');
    expect(app(CashSessionService::class)->current())->toBeNull();
});

test('it rejects closing with a cash difference and no note', function (string $countedCash) {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);

    expect(fn () => app(CashSessionService::class)->close(User::factory()->create(), $countedCash, '0'))
        ->toThrow(failsOn('closing_note'));
    expect($session->fresh()->isOpen())->toBeTrue();
})->with([
    'shortage' => '950',
    'surplus' => '1020',
]);

test('it closes with a cash difference when a note explains it', function () {
    CashSession::factory()->create(['opening_cash' => 1000]);

    $closed = app(CashSessionService::class)->close(User::factory()->create(), '950', '500', 'Faltaron $50, se revisa mañana');

    expect($closed->cash_difference)->toBe('-50.00');
    expect($closed->closing_note)->toBe('Faltaron $50, se revisa mañana');
});

test('it rejects leaving more cash than was counted', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);

    expect(fn () => app(CashSessionService::class)->close(User::factory()->create(), '1000', '1200'))
        ->toThrow(failsOn('cash_left'));
    expect($session->fresh()->isOpen())->toBeTrue();
});

test('it rejects movements and a second close once the register is closed', function () {
    CashSession::factory()->create(['opening_cash' => 1000]);
    $service = app(CashSessionService::class);
    $user = User::factory()->create();
    $service->close($user, '1000', '1000');

    expect(fn () => $service->recordMovement($user, CashMovementType::Outflow, 'Flete', '780', 'Coda'))
        ->toThrow(failsOn('cash_session'));
    expect(fn () => $service->close($user, '1000', '1000'))->toThrow(failsOn('cash_session'));
    expect(CashMovement::count())->toBe(0);
});

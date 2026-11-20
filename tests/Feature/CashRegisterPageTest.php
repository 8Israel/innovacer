<?php

use App\Models\CashMovement;
use App\Models\CashMovementCategory;
use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

test('it offers to open the register with the fund left by the last closing', function () {
    CashSession::factory()->closed('1000.00')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('cash.index'))
        ->assertOk()
        ->assertSee('Abrir caja')
        ->assertSee('El último corte dejó $1,000.00 de fondo.');
});

test('it opens the register', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::cash.index')
        ->set('opening_cash', '1000')
        ->call('openRegister')
        ->assertHasNoErrors()
        ->assertDispatched('cash-session-changed');

    expect(CashSession::query()->sole()->only(['status', 'opening_cash', 'opened_by_id']))->toBe([
        'status' => CashSessionStatus::Open,
        'opening_cash' => '1000.00',
        'opened_by_id' => $user->id,
    ]);
});

test('it asks for a note when the fund differs from the last closing', function () {
    CashSession::factory()->closed('1000.00')->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::cash.index')
        ->set('opening_cash', '800')
        ->call('openRegister')
        ->assertHasErrors('opening_note');

    expect(CashSession::query()->where('status', CashSessionStatus::Open)->exists())->toBeFalse();
});

test('it shows the expected cash of the design example', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    SalePayment::factory()->for($session)->create(['method' => PaymentMethod::Cash, 'amount' => 14230]);
    CustomerPayment::factory()->for($session)->create(['method' => PaymentMethod::Cash, 'amount' => 1500]);
    CashMovement::factory()->for($session)->create(['type' => CashMovementType::Outflow, 'amount' => 1180]);

    $this->actingAs(User::factory()->create())
        ->get(route('cash.index'))
        ->assertSee('Caja de hoy')
        ->assertSeeInOrder(['Debe haber', '$15,550.00']);
});

test('it records a supplier expense as a cash outflow', function () {
    $session = CashSession::factory()->create();
    $coda = Supplier::factory()->create(['name' => 'Coda']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::cash.index')
        ->set('amount', '780')
        ->set('category', 'supplier_expense')
        ->set('supplier_id', $coda->id)
        ->set('note', 'Flete, recibo 311')
        ->call('recordOutflow')
        ->assertHasNoErrors();

    expect($session->cashMovements()->sole()->only(['category', 'supplier_id', 'beneficiary', 'amount']))->toBe([
        'category' => CashMovementCategory::SupplierExpense,
        'supplier_id' => $coda->id,
        'beneficiary' => 'Coda',
        'amount' => '780.00',
    ]);
});

test('it closes the register with the counted cash', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::cash.index')
        ->set('counted_cash', '1000')
        ->set('cash_left', '1000')
        ->call('closeRegister')
        ->assertHasNoErrors()
        ->assertDispatched('cash-session-changed');

    expect($session->fresh()->status)->toBe(CashSessionStatus::Closed);
});

test('it asks for a note to close with a difference', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::cash.index')
        ->set('counted_cash', '950')
        ->assertSee('−$50.00')
        ->set('cash_left', '950')
        ->call('closeRegister')
        ->assertHasErrors('closing_note');

    expect($session->fresh()->isOpen())->toBeTrue();
});

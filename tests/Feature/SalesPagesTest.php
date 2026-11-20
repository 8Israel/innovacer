<?php

use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use Livewire\Livewire;

/**
 * Like sale #1044 of docs/diseno/VentaDetalle.html: 6 Calcio 1 L at $185 with the
 * customer's 10 % discount, paid with $1,000 in cash.
 *
 * @return array{sale: Sale, calcio: Product, mostrador: Warehouse, session: CashSession}
 */
function calcioSale(): array
{
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'sku' => 'CAL-01', 'unit_price' => 185, 'tax_rate' => 0, 'ieps_rate' => null]);
    $stock = stockLot($calcio, $mostrador, '20', '2026-09-28');
    $stock->lot->update(['supplier_id' => Supplier::factory()->create(['name' => 'Coda'])->id]);
    $customer = Customer::factory()->create(['name' => 'Francisco Castañón', 'discount_rate' => 0.10]);

    $sale = app(SaleService::class)->sell(
        User::factory()->create(['name' => 'Encargada']),
        $mostrador,
        [['product_id' => $calcio->id, 'quantity' => '6']],
        [['method' => PaymentMethod::Cash, 'amount' => '999.00', 'received_amount' => '1000']],
        $customer,
        notes: 'Se lo llevó el Ing. Meléndez.',
    );

    return compact('sale', 'calcio', 'mostrador', 'session');
}

test('the sales list shows the status of each sale and filters them', function () {
    $paid = Sale::factory()->create(['folio' => 'V-000001', 'total' => 500]);
    $paid->payments()->create(['method' => PaymentMethod::Cash, 'amount' => 500]);
    $receivable = Sale::factory()->for(Customer::factory()->withCredit())->create(['folio' => 'V-000002', 'total' => 800]);
    Sale::factory()->create(['folio' => 'V-000003', 'status' => SaleStatus::Canceled]);
    Sale::factory()->pending()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::sales.index')
        ->assertSeeInOrder(['V-000003', 'Cancelada'])
        ->assertSeeInOrder(['V-000002', 'A crédito', '$800.00', 'Por cobrar'])
        ->assertSeeInOrder(['V-000001', 'Efectivo', '$500.00', 'Pagada'])
        ->set('status', 'receivable')
        ->assertSee('V-000002')
        ->assertDontSee('V-000001')
        ->assertDontSee('V-000003')
        ->set('status', 'paid')
        ->assertSee('V-000001')
        ->assertDontSee('V-000002');

    expect($receivable->fresh()->status)->toBe(SaleStatus::Completed);
});

test('the sale detail shows the lots, the totals and the history', function () {
    ['sale' => $sale] = calcioSale();

    $this->actingAs(User::factory()->create())
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertSeeInOrder(['Venta V-000001', 'Pagada', 'Sin facturar', 'Reimprimir ticket', 'Nota de venta'])
        ->assertSeeInOrder(['Francisco Castañón', 'Mostrador Innovacer', 'Encargada', 'Observaciones: Se lo llevó el Ing. Meléndez.'])
        ->assertSeeInOrder(['Calcio 1 L', 'CAL-01 · lote Coda 28 sep', '6', '$185.00', '$1,110.00'])
        ->assertSeeInOrder(['Subtotal', '$1,110.00', 'Descuento', '$111.00', 'Total', '$999.00', 'Pagó $1,000.00 en efectivo · cambio $1.00'])
        ->assertSee('Venta registrada por Encargada; salieron 6 piezas de Mostrador Innovacer');
});

test('it registers a return refunded in cash at what the customer paid', function () {
    ['sale' => $sale, 'calcio' => $calcio, 'mostrador' => $mostrador, 'session' => $session] = calcioSale();
    $calcioItem = $sale->items()->sole();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::sales.show', ['sale' => $sale])
        ->set("return_quantities.{$calcioItem->id}", '1')
        ->assertSee('$166.50')
        ->set('return_reason', 'El cliente compró de más')
        ->call('registerReturn')
        ->assertHasNoErrors()
        ->assertSee('Devolución parcial')
        ->assertSee('de 5');

    $return = CustomerReturn::query()->sole();
    expect($return->only(['refund_method', 'refund_amount', 'restocked', 'reason']))->toBe([
        'refund_method' => RefundMethod::Cash,
        'refund_amount' => '166.50',
        'restocked' => true,
        'reason' => 'El cliente compró de más',
    ]);
    expect(stockOf($calcio, $mostrador))->toBe('15.000');
    expect($return->cash_session_id)->toBe($session->id);
});

test('a damaged return does not go back to stock', function () {
    ['sale' => $sale, 'calcio' => $calcio, 'mostrador' => $mostrador] = calcioSale();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::sales.show', ['sale' => $sale])
        ->set('return_quantities.'.$sale->items()->sole()->id, '2')
        ->set('return_destination', 'waste')
        ->call('registerReturn')
        ->assertHasNoErrors();

    expect(CustomerReturn::query()->sole()->restocked)->toBeFalse();
    expect(stockOf($calcio, $mostrador))->toBe('14.000');
});

test('the owner cancels a sale from its detail with their password', function () {
    ['sale' => $sale, 'calcio' => $calcio, 'mostrador' => $mostrador, 'session' => $session] = calcioSale();
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::sales.show', ['sale' => $sale])
        ->assertSee('Se devuelve en efectivo $999.00')
        ->set('cancel_reason', 'El cliente se arrepintió')
        ->set('owner_password', 'wrong')
        ->call('cancelSale')
        ->assertHasErrors('authorization')
        ->set('owner_password', 'password')
        ->call('cancelSale')
        ->assertHasNoErrors()
        ->assertSee('Motivo: El cliente se arrepintió');

    expect($sale->fresh()->status)->toBe(SaleStatus::Canceled);
    expect(stockOf($calcio, $mostrador))->toBe('20.000');
    expect($session->cashMovements()->sole()->type)->toBe(CashMovementType::Outflow);
});

test('a clerk does not see the cancel button nor can cancel', function () {
    ['sale' => $sale] = calcioSale();
    $clerk = User::factory()->create();

    $this->actingAs($clerk)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertDontSee('Cancelar venta');

    Livewire::actingAs($clerk)
        ->test('pages::sales.show', ['sale' => $sale])
        ->set('cancel_reason', 'El cliente se arrepintió')
        ->set('owner_password', 'password')
        ->call('cancelSale')
        ->assertForbidden();

    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);
});

<?php

use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashSessionService;
use App\Services\SaleService;

/**
 * Three closings of docs/diseno/CajaHistorial.html (one squares, one is $20 over,
 * one $100 short) and today's register still open.
 */
function octoberClosings(): array
{
    $encargada = User::factory()->create(['name' => 'Encargada']);
    $close = fn (string $day, string $expected, string $counted, ?string $note) => CashSession::factory()->closed()->create([
        'opened_by_id' => $encargada->id,
        'closed_by_id' => $encargada->id,
        'opened_at' => "2026-10-{$day} 08:00",
        'closed_at' => "2026-10-{$day} 19:00",
        'expected_cash' => $expected,
        'counted_cash' => $counted,
        'cash_difference' => bcsub($counted, $expected, 2),
        'closing_note' => $note,
    ]);

    return [
        'squared' => $close('05', '12640', '12640', null),
        'over' => $close('07', '11240', '11260', 'Cliente no esperó el cambio'),
        'short' => $close('08', '14820', '14720', 'Cambio mal entregado'),
        'open' => CashSession::factory()->create(['opened_by_id' => $encargada->id, 'opened_at' => '2026-10-09 08:00', 'opening_cash' => 1500]),
    ];
}

test('the history lists each closing with its difference and the month totals', function () {
    $this->travelTo('2026-10-09 12:00');
    octoberClosings();

    $this->actingAs(User::factory()->create())
        ->get(route('cash.history'))
        ->assertOk()
        ->assertSeeInOrder(['Cortes en las fechas', '3', 'Cortes con diferencia', '2', 'Diferencia acumulada', '−$80.00'], escape: false)
        ->assertSeeInOrder(['Vie 9 oct', 'Encargada', 'Abierta', '$1,500.00', '—'])
        ->assertSeeInOrder(['Jue 8 oct', '$14,820.00', '$14,720.00', 'Faltan $100.00', 'Cambio mal entregado', 'Imprimir'])
        ->assertSeeInOrder(['Mié 7 oct', '$11,240.00', '$11,260.00', 'Sobran $20.00', 'Cliente no esperó el cambio'])
        ->assertSeeInOrder(['Lun 5 oct', '$12,640.00', '$12,640.00', 'Cuadra']);
});

test('the dates narrow the closings shown', function () {
    $this->travelTo('2026-10-09 12:00');
    octoberClosings();

    $this->actingAs(User::factory()->create())
        ->get(route('cash.history', ['desde' => '2026-10-07', 'hasta' => '2026-10-07']))
        ->assertSee('Mié 7 oct')
        ->assertDontSee('Jue 8 oct')
        ->assertDontSee('Lun 5 oct');
});

test('a closing shows its sales by method, its movements and how the expected cash came out', function () {
    $session = CashSession::factory()->create(['opening_cash' => 1000]);
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['unit_price' => 500, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($product, $mostrador, '10', '2026-10-01');
    $owner = User::factory()->owner()->create();
    app(SaleService::class)->sell($owner, $mostrador, [['product_id' => $product->id, 'quantity' => '2']], [['method' => PaymentMethod::Cash, 'amount' => '1000']]);
    app(SaleService::class)->sell($owner, $mostrador, [['product_id' => $product->id, 'quantity' => '1']], [['method' => PaymentMethod::Card, 'amount' => '500', 'reference' => 'Visa 0921']], Customer::factory()->create());
    app(CashSessionService::class)->recordMovement($owner, CashMovementType::Outflow, 'Comida', '150', 'Encargada');
    app(CashSessionService::class)->close($owner, '1800', '1000', 'Faltaron 50 del cambio');

    $this->actingAs(User::factory()->create())
        ->get(route('cash.show', $session))
        ->assertOk()
        ->assertSeeInOrder(['Efectivo', '$1,000.00', 'Tarjeta', '$500.00', 'Vendido', '$1,500.00', '2 ventas'])
        ->assertSeeInOrder(['Comida', 'Encargada', '$150.00'])
        ->assertSeeInOrder(['Fondo inicial', '$1,000.00', 'Ventas en efectivo', '+$1,000.00', 'Salidas', '$150.00', 'Debía haber', '$1,850.00', 'Contado', '$1,800.00', 'Faltan $50.00', 'Fondo que se dejó', '$1,000.00'])
        ->assertSee('Nota: Faltaron 50 del cambio');

    $this->get(route('cash.print', $session))
        ->assertOk()
        ->assertSeeInOrder(['CORTE DE CAJA', 'Ventas', '1,500.00', 'Debía haber', '1,850.00', 'Contado', '1,800.00', 'Faltan', '50.00', 'Comida', 'Firma de quien entrega']);
});

test('an open register has no printed closing yet', function () {
    $session = CashSession::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('cash.print', $session))->assertNotFound();
});

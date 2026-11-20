<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use App\Services\SettingsService;

/**
 * Sale #1044 from the design: Francisco Castañón for Reynosa, 10 % discount,
 * 2 Ultra Nitrógeno at $1,250 and 6 Calcio 1 L at $185, paid with $3,000 in cash.
 */
function sale1044(): Sale
{
    CashSession::factory()->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $nitrogeno = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L', 'sku' => 'UNI-20', 'unit_price' => 1250, 'tax_rate' => 0, 'ieps_rate' => null]);
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'sku' => 'CAL-01', 'unit_price' => 185, 'tax_rate' => 0, 'ieps_rate' => null]);
    stockLot($nitrogeno, $mostrador, '12', '2026-10-01');
    stockLot($calcio, $mostrador, '20', '2026-10-01');
    $customer = Customer::factory()->create(['name' => 'Francisco Castañón', 'discount_rate' => 0.10]);
    $reynosa = CustomerDestination::factory()->for($customer)->create(['name' => 'Reynosa']);

    return app(SaleService::class)->sell(
        User::factory()->create(['name' => 'Encargada']),
        $mostrador,
        [['product_id' => $nitrogeno->id, 'quantity' => '2'], ['product_id' => $calcio->id, 'quantity' => '6']],
        [['method' => PaymentMethod::Cash, 'amount' => '3249.00', 'received_amount' => '3500']],
        $customer,
        $reynosa,
        notes: 'Se lo llevó el Ing. Meléndez para el rancho de Reynosa.',
    );
}

beforeEach(function () {
    app(SettingsService::class)->save([
        'business_name' => 'Innovacer Agrotechnology',
        'business_address' => 'Carretera a Reynosa km 4',
        'business_phone' => '899 123 4567',
    ]);
});

test('the ticket shows the business data, the lines and the change', function () {
    $sale = sale1044();

    $this->actingAs(User::factory()->create())
        ->get(route('sales.ticket', $sale))
        ->assertOk()
        ->assertSeeInOrder([
            'Innovacer Agrotechnology', 'Carretera a Reynosa km 4', 'Tel. 899 123 4567',
            'Venta V-000001', 'Atendió: Encargada', 'Cliente: Francisco Castañón', 'Destino: Reynosa',
            'ULTRA NITRÓGENO 20 L', '2 x 1,250.00', '2,500.00',
            'CALCIO 1 L', '6 x 185.00', '1,110.00',
            'Subtotal', '3,610.00', 'Descuento', '-361.00', 'TOTAL', '$3,249.00',
            'Efectivo', '3,500.00', 'Cambio', '251.00',
            '8 piezas', 'Este ticket no es comprobante fiscal',
        ]);
});

test('the sale note shows the business data and the total in words', function () {
    $sale = sale1044();

    $this->actingAs(User::factory()->create())
        ->get(route('sales.note', $sale))
        ->assertOk()
        ->assertSeeInOrder([
            'Carretera a Reynosa km 4 · Tel. 899 123 4567', 'NOTA DE VENTA', 'Folio V-000001',
            'Francisco Castañón', 'Reynosa', 'Efectivo', 'Encargada',
            'UNI-20', 'Ultra Nitrógeno 20 L', 'CAL-01', 'Calcio 1 L',
            'Se lo llevó el Ing. Meléndez para el rancho de Reynosa.',
            'Total', '$3,249.00', 'Tres mil doscientos cuarenta y nueve pesos 00/100 M.N.',
            'Recibí de conformidad',
        ]);
});

test('it uses the 58 mm ticket width when configured', function () {
    $sale = sale1044();
    app(SettingsService::class)->save(['ticket_printer_width' => '58']);

    $this->actingAs(User::factory()->create())
        ->get(route('sales.ticket', $sale))
        ->assertSee('size: 58mm auto', escape: false);
});

test('a pending sale has no documents yet', function () {
    $pending = Sale::factory()->pending()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('sales.ticket', $pending))
        ->assertNotFound();
});

test('a saved setting is read again without the old cached value', function () {
    expect(setting('business_phone'))->toBe('899 123 4567');

    app(SettingsService::class)->save(['business_phone' => '899 765 4321']);

    expect(setting('business_phone'))->toBe('899 765 4321');
    expect(setting('missing_key', 'por defecto'))->toBe('por defecto');
});

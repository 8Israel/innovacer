<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerBalanceService;
use Livewire\Livewire;

/**
 * Francisco Castañón from the design (docs/diseno/EstadoCuenta.html).
 *
 * @return array{customer: Customer, reynosa: CustomerDestination, jalincher: CustomerDestination, sale0981: Sale, sale1012: Sale}
 */
function castanonStatement(): array
{
    $customer = Customer::factory()->withCredit()->create(['name' => 'Francisco Castañón', 'rfc' => null]);
    $reynosa = CustomerDestination::factory()->for($customer)->create(['name' => 'Reynosa']);
    $jalincher = CustomerDestination::factory()->for($customer)->create(['name' => 'Jalincher']);

    $sale0981 = Sale::factory()->for($customer)->for($reynosa)->create(['folio' => 'V-000981', 'total' => 18000, 'sold_at' => '2026-08-25 10:00']);
    $sale0995 = Sale::factory()->for($customer)->for($jalincher)->create(['folio' => 'V-000995', 'total' => 7000, 'sold_at' => '2026-09-03 10:00']);
    $sale1012 = Sale::factory()->for($customer)->for($reynosa)->create(['folio' => 'V-001012', 'total' => 6200, 'sold_at' => '2026-09-21 10:00']);

    $pay = function (Sale $sale, CustomerDestination $destination, PaymentMethod $method, string $amount, string $paidAt, ?string $reference = null) use ($customer): void {
        $payment = CustomerPayment::factory()->for($customer)->create([
            'customer_destination_id' => $destination->id,
            'method' => $method,
            'amount' => $amount,
            'reference' => $reference,
            'paid_at' => $paidAt,
        ]);
        CustomerPaymentAllocation::factory()->for($payment)->for($sale)->create(['amount' => $amount]);
    };

    $pay($sale0981, $reynosa, PaymentMethod::Transfer, '14000', '2026-08-28 10:00', '4471');
    $pay($sale0995, $jalincher, PaymentMethod::Card, '2800', '2026-09-30 10:00', 'Visa 0921');
    $pay($sale0981, $reynosa, PaymentMethod::Cash, '2000', '2026-10-08 10:00');

    return compact('customer', 'reynosa', 'jalincher', 'sale0981', 'sale1012');
}

test('it shows the balance by account, the sales and the movements of the design', function () {
    ['customer' => $customer] = castanonStatement();

    $this->actingAs(User::factory()->create())
        ->get(route('customers.statement', $customer))
        ->assertOk()
        ->assertSee('Cliente de crédito · crédito abierto, sin límite · RFC [POR CAPTURAR]')
        ->assertSeeInOrder(['Saldo total', '$12,400.00', 'Último abono: 8 oct', 'Cuenta Reynosa', '$8,200.00', '2 ventas con saldo', 'Cuenta Jalincher', '$4,200.00', '1 venta con saldo'])
        ->assertSeeInOrder([
            'V-001012', 'Reynosa', '$6,200.00', '$0.00', '$6,200.00', 'Pendiente',
            'V-000995', 'Jalincher', '$7,000.00', '$2,800.00', '$4,200.00', 'Parcial',
            'V-000981', 'Reynosa', '$18,000.00', '$16,000.00', '$2,000.00', 'Parcial',
        ])
        ->assertSeeInOrder([
            'Abono · efectivo · aplicado a V-000981', 'Reynosa', '$2,000.00', '$8,200.00',
            'Abono · tarjeta · aplicado a V-000995 · ref. Visa 0921', 'Jalincher', '$2,800.00', '$4,200.00',
            'Venta V-001012', 'Reynosa', '$6,200.00', '$10,200.00',
            'Venta V-000995', 'Jalincher', '$7,000.00', '$7,000.00',
            'Abono · transferencia · aplicado a V-000981 · ref. 4471', 'Reynosa', '$14,000.00', '$4,000.00',
            'Venta V-000981', 'Reynosa', '$18,000.00', '$18,000.00',
        ]);
});

test('it previews and registers a payment spread over the account sales', function () {
    CashSession::factory()->create();
    ['customer' => $customer, 'reynosa' => $reynosa, 'sale1012' => $sale1012] = castanonStatement();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::customers.statement', ['customer' => $customer])
        ->set('customer_destination_id', $reynosa->id)
        ->set('amount', '5000')
        ->assertSee('Liquida V-000981 ($2,000.00)')
        ->assertSee('Abona $3,000.00 a V-001012')
        ->assertSee('Saldo queda en $3,200.00')
        ->set('method', 'transfer')
        ->set('reference', 'ref. 5520')
        ->call('registerPayment')
        ->assertHasNoErrors()
        ->assertSee('$3,200.00');

    expect(app(CustomerBalanceService::class)->destinationBalance($reynosa))->toBe('3200.00');
    expect(app(CustomerBalanceService::class)->pending($sale1012))->toBe('3200.00');
});

test('it marks a settled sale with the date it was settled', function () {
    CashSession::factory()->create();
    ['customer' => $customer, 'reynosa' => $reynosa] = castanonStatement();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::customers.statement', ['customer' => $customer])
        ->set('customer_destination_id', $reynosa->id)
        ->set('amount', '2000')
        ->call('registerPayment')
        ->assertSee('Liquidada '.now()->translatedFormat('j M'));
});

test('it does not register a payment without an open register', function () {
    ['customer' => $customer, 'reynosa' => $reynosa] = castanonStatement();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::customers.statement', ['customer' => $customer])
        ->assertSee('La caja está cerrada')
        ->set('customer_destination_id', $reynosa->id)
        ->set('amount', '1000')
        ->call('registerPayment')
        ->assertHasErrors('cash_session');

    expect(app(CustomerBalanceService::class)->destinationBalance($reynosa))->toBe('8200.00');
});

test('it filters the movements by account', function () {
    ['customer' => $customer, 'jalincher' => $jalincher] = castanonStatement();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::customers.statement', ['customer' => $customer])
        ->set('ledger_destination_id', (string) $jalincher->id)
        ->assertSee('Venta V-000995')
        ->assertDontSee('Venta V-001012');
});

test('the printable statement shows the balances and the movements', function () {
    ['customer' => $customer] = castanonStatement();

    $this->actingAs(User::factory()->create())
        ->get(route('customers.statement.print', $customer))
        ->assertOk()
        ->assertSeeInOrder(['ESTADO DE CUENTA', 'Francisco Castañón', '$12,400.00', 'Cuenta Reynosa', '$8,200.00', 'Cuenta Jalincher', '$4,200.00'])
        ->assertSeeInOrder(['Venta V-000981', '$18,000.00', 'Abono · transferencia', '$14,000.00', '$4,000.00', 'Abono · efectivo', '$2,000.00', '$8,200.00'])
        ->assertSee('El saldo de cada renglón es el de su cuenta.');
});

<?php

use App\Models\CashSession;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierSettlementType;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SupplierDebtService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * NH sold to the business: 12 Bigfix 1 L at $350 in September and 10 Sumo 1 L at $280 in October.
 *
 * @return array{nh: Supplier, bigfix: Product, sumo: Product}
 */
function nhPurchases(): array
{
    $nh = Supplier::factory()->purchase()->create(['name' => 'NH']);
    $bigfix = Product::factory()->create(['name' => 'Bigfix 1 L']);
    $sumo = Product::factory()->create(['name' => 'Sumo 1 L']);
    $inventory = app(InventoryService::class);
    $warehouse = Warehouse::factory()->create();

    $inventory->receive(User::factory()->create(), $nh, $warehouse, [['product_id' => $bigfix->id, 'quantity' => '12', 'unit_cost' => '350']], false, receivedAt: Carbon::parse('2026-09-15'));
    $inventory->receive(User::factory()->create(), $nh, $warehouse, [['product_id' => $sumo->id, 'quantity' => '10', 'unit_cost' => '280']], false, receivedAt: Carbon::parse('2026-10-02'));

    return compact('nh', 'bigfix', 'sumo');
}

test('the list shows what is owed to each supplier', function () {
    nhPurchases();
    Supplier::factory()->inKind()->create(['name' => 'Francisco Rodríguez']);

    $this->actingAs(User::factory()->create())
        ->get(route('suppliers.index'))
        ->assertOk()
        ->assertSeeInOrder(['NH', 'Compra', '2', '$7,000.00', 'Francisco Rodríguez', 'En especie', '—'])
        ->assertSee('Total por pagar $7,000.00');
});

test('it creates a consignment supplier with its own warehouse', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::suppliers.index')
        ->set('name', 'Ortitec')
        ->set('settlement_type', 'consignment')
        ->set('with_warehouse', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $ortitec = Supplier::query()->where('name', 'Ortitec')->sole();
    expect($ortitec->settlement_type)->toBe(SupplierSettlementType::Consignment);
    expect(Warehouse::query()->where('name', 'Bodega Ortitec')->sole()->only(['code', 'lender_id']))->toBe(['code' => 'ORT', 'lender_id' => null]);
});

test('the supplier file previews and registers a payment spread over the oldest debt', function () {
    CashSession::factory()->create(['opening_cash' => 10000]);
    ['nh' => $nh] = nhPurchases();

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::suppliers.show', ['supplier' => $nh])
        ->assertSeeInOrder(['Por pagar (ya vendido)', '$7,000.00', 'Bigfix 1 L', '12', '$350.00', '$4,200.00', 'Sumo 1 L', '10', '$280.00', '$2,800.00'])
        ->set('amount', '5000')
        ->assertSeeInOrder(['Bigfix 1 L', 'Se liquida', 'Sumo 1 L', 'Abona $800.00', 'quedan $2,000.00'])
        ->assertSeeInOrder(['Queda por pagar', '$2,000.00'])
        ->set('method', 'cash')
        ->set('reference', 'Recibo 120')
        ->call('registerPayment')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Pagos anteriores', 'Efectivo', 'Recibo 120', 'Bigfix 1 L, Sumo 1 L', '$5,000.00']);

    expect(app(SupplierDebtService::class)->pending($nh))->toBe('2000.00');
    expect(SupplierPayment::query()->sole()->cash_session_id)->not->toBeNull();
});

test('a payment can be spread by hand', function () {
    ['nh' => $nh, 'sumo' => $sumo] = nhPurchases();

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::suppliers.show', ['supplier' => $nh])
        ->set('spread', 'manual')
        ->set("product_amounts.{$sumo->id}", '2800')
        ->assertSet('amount', '2800.00')
        ->call('registerPayment')
        ->assertHasNoErrors();

    expect(app(SupplierDebtService::class)->products($nh)->map(fn (array $row) => $row['product']->name)->all())->toBe(['Bigfix 1 L']);
});

test('the clerk sees the debt but cannot pay', function () {
    ['nh' => $nh] = nhPurchases();
    $clerk = User::factory()->create();

    $this->actingAs($clerk)
        ->get(route('suppliers.show', $nh))
        ->assertOk()
        ->assertSee('$7,000.00')
        ->assertDontSee('Guardar pago');

    Livewire::actingAs($clerk)
        ->test('pages::suppliers.show', ['supplier' => $nh])
        ->set('amount', '100')
        ->set('method', 'transfer')
        ->call('registerPayment')
        ->assertForbidden();

    expect(SupplierPayment::count())->toBe(0);
});

test('the way a supplier works cannot change once it has product', function () {
    ['nh' => $nh] = nhPurchases();

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::suppliers.show', ['supplier' => $nh])
        ->call('editSupplier')
        ->set('settlement_type', 'consignment')
        ->call('saveSupplier')
        ->assertHasErrors('settlement_type')
        ->set('settlement_type', 'purchase')
        ->set('phone', '899 555 0101')
        ->call('saveSupplier')
        ->assertHasNoErrors();

    expect($nh->fresh()->only(['settlement_type', 'phone']))->toBe(['settlement_type' => SupplierSettlementType::Purchase, 'phone' => '899 555 0101']);
});

<?php

use App\Models\LoanEntry;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Livewire\Livewire;

test('it receives consignment merchandise previewing the new stock', function () {
    $biotexa = Supplier::factory()->create(['name' => 'Biotexa']);
    $mostrador = Warehouse::factory()->sellable()->create();
    $nitrogeno = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L', 'reference_cost' => 950]);
    stockLot($nitrogeno, $mostrador, '12', '2026-09-01');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.entry')
        ->set('supplier_id', $biotexa->id)
        ->assertSet('arrival', 'consignment')
        ->call('addProduct', $nitrogeno->id)
        ->assertSet("lines.{$nitrogeno->id}.unit_cost", '950.00')
        ->set("lines.{$nitrogeno->id}.quantity", '10')
        ->set("lines.{$nitrogeno->id}.unit_cost", '980')
        ->assertSee('12 → 22')
        ->assertSee('A Biotexa se le irá debiendo conforme se venda, no antes.')
        ->call('receive')
        ->assertHasNoErrors()
        ->assertSet('lines', []);

    expect(stockOf($nitrogeno, $mostrador))->toBe('22.000');
    expect(Lot::query()->latest('id')->first()->only(['owner_supplier_id', 'unit_cost']))->toBe(['owner_supplier_id' => $biotexa->id, 'unit_cost' => '980.00']);
});

test('it receives bought merchandise as the business own stock', function () {
    $nh = Supplier::factory()->purchase()->create();
    Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.entry')
        ->set('supplier_id', $nh->id)
        ->assertSet('arrival', 'purchase')
        ->call('addProduct', $product->id)
        ->set("lines.{$product->id}.unit_cost", '200')
        ->call('receive')
        ->assertHasNoErrors();

    expect(Lot::query()->sole()->owner_supplier_id)->toBeNull();
});

test('it requires the supplier to receive merchandise', function () {
    Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.entry')
        ->call('addProduct', $product->id)
        ->call('receive')
        ->assertHasErrors('supplier_id');

    expect(Lot::count())->toBe(0);
});

test('it transfers from Francisco consignment warning that it must be repaid', function () {
    $francisco = Supplier::factory()->inKind()->create(['name' => 'Francisco Rodríguez']);
    $consigna = Warehouse::factory()->lentBy($francisco)->create(['name' => 'Consigna Francisco']);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $calcio = Product::factory()->create(['name' => 'Ultra Calcio 20 L']);
    stockLot($calcio, $consigna, '47', '2026-09-01');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.transfer')
        ->set('from_warehouse_id', $consigna->id)
        ->assertSee('Dueño del producto: Francisco Rodríguez')
        ->call('addProduct', $calcio->id)
        ->set("lines.{$calcio->id}.quantity", '3')
        ->assertSee('Queda pendiente reponer 3 Ultra Calcio 20 L a Francisco Rodríguez.')
        ->set('note', 'Pedido del dueño para cliente de Reynosa.')
        ->call('transfer')
        ->assertHasNoErrors()
        ->assertSee('Ultra Calcio 20 L');

    expect(stockOf($calcio, $consigna))->toBe('44.000');
    expect(stockOf($calcio, $mostrador))->toBe('3.000');
    expect(LoanEntry::query()->sole()->quantity)->toBe('3.000');
});

test('it requires a note to transfer and does not offer a lender warehouse as destination', function () {
    $origin = Warehouse::factory()->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $consigna = Warehouse::factory()->lentBy()->create(['name' => 'Consigna Francisco']);
    $product = Product::factory()->create();
    stockLot($product, $origin, '10', '2026-09-01');

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.transfer')
        ->set('from_warehouse_id', $origin->id)
        ->call('addProduct', $product->id)
        ->call('transfer')
        ->assertHasErrors('note');

    expect($component->instance()->destinations->modelKeys())->not->toContain($consigna->id)->toContain($mostrador->id);
    expect(stockOf($product, $origin))->toBe('10.000');
});

test('it lists pending loans and settles one with a repayment', function () {
    $francisco = Supplier::factory()->inKind()->create(['name' => 'Francisco Rodríguez']);
    $consigna = Warehouse::factory()->lentBy($francisco)->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $magnesio = Product::factory()->create(['name' => 'Ultra Magnesio 20 L', 'reference_cost' => 1150]);
    stockLot($magnesio, $consigna, '10', '2026-09-01');
    stockLot($magnesio, $mostrador, '6', '2026-09-20');
    $user = User::factory()->create();
    app(InventoryService::class)->transfer($user, $consigna, $mostrador, [['product_id' => $magnesio->id, 'quantity' => '4']], 'Reponer con el siguiente envío');
    $loan = LoanEntry::query()->sole();
    app(InventoryService::class)->repayLoan($user, $loan, '2', $mostrador);

    Livewire::actingAs($user)
        ->test('pages::inventory.loans')
        ->assertSee('2 piezas')
        ->assertSee('$2,300.00 a costo de referencia')
        ->assertSeeInOrder(['Ultra Magnesio 20 L', 'Reponer con el siguiente envío', 'Francisco Rodríguez', '4', '2', '2', 'parcial'])
        ->call('startRepayment', $loan->id)
        ->assertSet('quantity', '2')
        ->assertSee('Este préstamo queda saldado.')
        ->call('repay')
        ->assertHasNoErrors()
        ->assertSee('No hay préstamos por reponer.');

    expect(app(InventoryService::class)->pendingQuantity($loan))->toBe('0.000');
    expect(stockOf($magnesio, $consigna))->toBe('10.000');
});

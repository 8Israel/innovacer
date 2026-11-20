<?php

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Livewire\Livewire;

/**
 * Ultra Nitrógeno from the design: 12 own in Mostrador (minimum 5), 25 in
 * Francisco's consignment, nothing in Bodega Ortitec.
 *
 * @return array{product: Product, mostrador: Warehouse, consigna: Warehouse, user: User}
 */
function nitrogenoKardex(): array
{
    $user = User::factory()->create(['name' => 'Encargada']);
    $francisco = Supplier::factory()->inKind()->create(['name' => 'Francisco Rodríguez']);
    $biotexa = Supplier::factory()->create(['name' => 'Biotexa']);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $consigna = Warehouse::factory()->lentBy($francisco)->create(['name' => 'Consigna Francisco']);
    Warehouse::factory()->create(['name' => 'Bodega Ortitec']);
    $product = Product::factory()->for($biotexa)->create(['name' => 'Ultra Nitrógeno 20 L', 'sku' => 'UNI-20', 'barcode' => '7501234500021', 'product_key' => null]);
    StockMinimum::factory()->for($product)->for($mostrador)->create(['min_quantity' => 5]);

    $inventory = app(InventoryService::class);
    $inventory->receive($user, $francisco, $consigna, [['product_id' => $product->id, 'quantity' => '30']], consignment: true);
    $inventory->receive($user, $biotexa, $mostrador, [['product_id' => $product->id, 'quantity' => '8', 'unit_cost' => '980']], consignment: false, note: 'Biotexa · nota de entrega 48');
    $inventory->exit($user, $mostrador, [['product_id' => $product->id, 'quantity' => '1']], 'Muestra', 'Ing. Meléndez', 'Para un experimento en parcela');
    $inventory->transfer($user, $consigna, $mostrador, [['product_id' => $product->id, 'quantity' => '5']], 'Reponer con el siguiente envío');

    return compact('product', 'mostrador', 'consigna', 'user');
}

test('it shows the stock by warehouse and owner and the movements newest first', function () {
    ['product' => $product, 'user' => $user] = nitrogenoKardex();

    $this->actingAs($user)
        ->get(route('inventory.kardex', $product))
        ->assertOk()
        ->assertSee('UNI-20 · Código de barras 7501234500021 · Proveedor Biotexa · Clave SAT [POR DEFINIR]')
        ->assertSeeInOrder(['Mostrador Innovacer', '12', 'Propio · mínimo 5', 'Consigna Francisco', '25', 'De Francisco Rodríguez', 'Bodega Ortitec', '0'])
        ->assertSeeInOrder(['Total físico', '37', '12 propios · 25 en consigna'])
        ->assertSeeInOrder([
            'Traspaso · préstamo', 'Mostrador Innovacer', '+5', '7', '12', 'Encargada', 'Reponer con el siguiente envío',
            'Traspaso · préstamo', 'Consigna Francisco', '−5', '30', '25',
            'Salida · muestra', 'Mostrador Innovacer', '−1', '8', '7', 'Ing. Meléndez · Para un experimento en parcela',
            'Entrada · propio', 'Mostrador Innovacer', '+8', '0', '8', 'Biotexa · nota de entrega 48',
        ]);
});

test('it registers an adjustment from a physical count', function () {
    ['product' => $product, 'mostrador' => $mostrador, 'user' => $user] = nitrogenoKardex();

    Livewire::actingAs($user)
        ->test('pages::inventory.kardex', ['product' => $product])
        ->call('startAdjustment', $mostrador->id)
        ->assertSee('El sistema dice')
        ->set('counted_quantity', '10')
        ->assertSee('−2')
        ->set('reason', 'shortage')
        ->set('adjust_note', 'Conteo físico')
        ->call('adjust')
        ->assertHasNoErrors()
        ->assertSee('Ajuste por conteo · Faltante sin explicar');

    expect(stockOf($product, $mostrador))->toBe('10.000');
});

test('it requires the reason of an adjustment', function () {
    ['product' => $product, 'mostrador' => $mostrador, 'user' => $user] = nitrogenoKardex();

    Livewire::actingAs($user)
        ->test('pages::inventory.kardex', ['product' => $product])
        ->call('startAdjustment', $mostrador->id)
        ->set('counted_quantity', '10')
        ->call('adjust')
        ->assertHasErrors('reason');

    expect(stockOf($product, $mostrador))->toBe('12.000');
});

test('it records an exit with who took the product', function () {
    ['product' => $product, 'mostrador' => $mostrador, 'user' => $user] = nitrogenoKardex();

    Livewire::actingAs($user)
        ->test('pages::inventory.kardex', ['product' => $product])
        ->call('startExit')
        ->assertSet('exit_warehouse_id', $mostrador->id)
        ->set('exit_quantity', '2')
        ->set('exit_reason', 'Experimento')
        ->set('responsible', 'Ing. Meléndez')
        ->call('recordExit')
        ->assertHasNoErrors();

    expect(stockOf($product, $mostrador))->toBe('10.000');
});

test('it filters the movements by type and warehouse', function () {
    ['product' => $product, 'consigna' => $consigna, 'user' => $user] = nitrogenoKardex();

    $component = Livewire::actingAs($user)
        ->test('pages::inventory.kardex', ['product' => $product])
        ->set('type', 'exit');

    expect($component->instance()->movements->getCollection()->every(fn (InventoryMovement $movement) => $movement->inventoryOperation->type->value === 'exit'))->toBeTrue();

    $component->set('type', '')->set('warehouse_id', $consigna->id);

    expect($component->instance()->movements->getCollection()->pluck('warehouse_id')->unique()->all())->toBe([$consigna->id]);
});

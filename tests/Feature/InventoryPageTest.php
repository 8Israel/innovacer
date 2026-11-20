<?php

use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Livewire\Livewire;

/**
 * Stock like the design: Mostrador, Consigna Francisco and Bodega Ortitec.
 *
 * @return array<string, mixed>
 */
function inventoryLikeTheDesign(): array
{
    $biotexa = Supplier::factory()->create(['name' => 'Biotexa']);
    $nh = Supplier::factory()->create(['name' => 'NH']);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $consigna = Warehouse::factory()->lentBy()->create(['name' => 'Consigna Francisco']);
    $bodega = Warehouse::factory()->create(['name' => 'Bodega Ortitec']);

    $nitrogeno = Product::factory()->for($biotexa)->create(['name' => 'Ultra Nitrógeno 20 L', 'sku' => 'UNI-20', 'unit_price' => 1250]);
    $magnesio = Product::factory()->for($biotexa)->create(['name' => 'Ultra Magnesio 20 L', 'sku' => 'UMG-20', 'unit_price' => 1480]);
    $mirabis = Product::factory()->for($nh)->create(['name' => 'Mirabis 1 L', 'sku' => 'MIR-01', 'unit_price' => 390]);
    $manguera = Product::factory()->withoutPrice()->create(['name' => 'Manguera de riego 16 mm', 'sku' => 'MAT-07']);

    stockLot($nitrogeno, $mostrador, '12', '2026-10-01');
    stockLot($nitrogeno, $consigna, '25', '2026-09-01');
    stockLot($magnesio, $mostrador, '6', '2026-10-01');
    stockLot($magnesio, $bodega, '20', '2026-09-15');
    stockLot($manguera, $mostrador, '40', '2026-09-01');
    StockMinimum::factory()->for($magnesio)->for($mostrador)->create(['min_quantity' => 10]);

    return compact('biotexa', 'nh', 'mostrador', 'consigna', 'bodega', 'nitrogeno', 'magnesio', 'mirabis', 'manguera');
}

test('it lists the stock of each product by warehouse with its status', function () {
    inventoryLikeTheDesign();

    $this->actingAs(User::factory()->create())
        ->get(route('inventory.index'))
        ->assertOk()
        ->assertSeeInOrder(['Mostrador Innovacer', 'Consigna Francisco', 'Bodega Ortitec', 'Total'])
        ->assertSeeInOrder(['Manguera de riego 16 mm', '40', '0', '0', '40', 'Sin precio', 'No se vende'])
        ->assertSeeInOrder(['Mirabis 1 L', 'NH', '$390.00', 'Agotado'])
        ->assertSeeInOrder(['Ultra Magnesio 20 L', 'Biotexa', '6', '0', '20', '26', '$1,480.00', 'Bajo mínimo'])
        ->assertSeeInOrder(['Ultra Nitrógeno 20 L', 'Biotexa', '12', '25', '0', '37', '$1,250.00', 'Disponible']);
});

test('it warns about products below the minimum or out of stock and filters them', function () {
    inventoryLikeTheDesign();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.index')
        ->assertSee('1 producto bajo el mínimo y 1 agotado en Mostrador Innovacer.')
        ->set('only_alerts', true)
        ->assertSee('Ultra Magnesio 20 L')
        ->assertSee('Mirabis 1 L')
        ->assertDontSee('Ultra Nitrógeno 20 L');
});

test('it filters by supplier and by warehouse with stock', function () {
    ['nh' => $nh, 'bodega' => $bodega] = inventoryLikeTheDesign();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.index')
        ->set('supplier_id', $nh->id)
        ->assertSee('Mirabis 1 L')
        ->assertDontSee('Ultra Nitrógeno 20 L')
        ->set('supplier_id', null)
        ->set('warehouse_id', $bodega->id)
        ->assertSee('Ultra Magnesio 20 L')
        ->assertDontSee('Ultra Nitrógeno 20 L');
});

test('it finds a product by its code', function () {
    inventoryLikeTheDesign();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.index')
        ->set('search', 'UNI-20')
        ->assertSee('Ultra Nitrógeno 20 L')
        ->assertDontSee('Mirabis 1 L');
});

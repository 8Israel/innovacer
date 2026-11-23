<?php

use App\Models\InventoryMovement;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Livewire\Livewire;

/**
 * @return array{mostrador: Warehouse, nitrogeno: Product, bigfix: Product, mirabis: Product}
 */
function countPageWarehouse(): array
{
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $nitrogeno = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L', 'sku' => 'UNI-20', 'reference_cost' => 980]);
    $bigfix = Product::factory()->create(['name' => 'Bigfix 1 L', 'sku' => 'BFX-01', 'reference_cost' => 350]);
    $mirabis = Product::factory()->create(['name' => 'Mirabis 1 L', 'sku' => 'MIR-01']);
    stockLot($nitrogeno, $mostrador, '12', '2026-09-01');
    stockLot($bigfix, $mostrador, '15', '2026-09-01');
    stockLot($mirabis, $mostrador, '0', '2026-09-01');

    return compact('mostrador', 'nitrogeno', 'bigfix', 'mirabis');
}

test('it counts a warehouse, shows the result at cost and applies it', function () {
    ['mostrador' => $mostrador, 'nitrogeno' => $nitrogeno, 'bigfix' => $bigfix] = countPageWarehouse();

    Livewire::actingAs(User::factory()->create(['name' => 'Encargada']))
        ->test('pages::inventory.count')
        ->call('start')
        ->assertSee('Iniciado hoy')
        ->assertSee('0 de 3 productos contados')
        ->set("counted.{$nitrogeno->id}", '10')
        ->set("counted.{$bigfix->id}", '16')
        ->assertSee('2 de 3 productos contados')
        ->assertSeeInOrder(['Bigfix 1 L', 'Sobra 1', 'Mirabis 1 L', 'Sin contar', 'UNI-20', 'Faltan 2'])
        ->assertSeeInOrder(['Resultado hasta ahora', 'Bigfix 1 L', '+$350.00', '$1,960.00', 'Diferencia a costo', '$1,610.00'])
        ->assertSee('Aplicar 2 ajustes')
        ->call('apply')
        ->assertHasNoErrors()
        ->assertSee('No hay un conteo en curso en Mostrador Innovacer');

    expect(PhysicalCount::query()->sole()->status)->toBe(PhysicalCountStatus::Applied);
    expect(InventoryMovement::query()->where('quantity', '!=', 0)->whereHas('inventoryOperation', fn ($operation) => $operation->where('reason', 'physical_count'))->count())->toBe(2);
    expect(stockOf($nitrogeno, $mostrador))->toBe('10.000');
});

test('what was typed is kept when coming back later', function () {
    ['nitrogeno' => $nitrogeno] = countPageWarehouse();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::inventory.count')
        ->call('start')
        ->set("counted.{$nitrogeno->id}", '11')
        ->call('saveForLater')
        ->assertRedirect(route('inventory.index'));

    Livewire::actingAs($user)
        ->test('pages::inventory.count')
        ->assertSet("counted.{$nitrogeno->id}", '11')
        ->assertSee('1 de 3 productos contados');
});

test('a product found on the shelf can be added to the count', function () {
    countPageWarehouse();
    $hose = Product::factory()->create(['name' => 'Manguera de riego 16 mm', 'sku' => 'MAT-07']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.count')
        ->call('start')
        ->set('search', 'Mangu')
        ->call('addProduct', $hose->id)
        ->assertSet("counted.{$hose->id}", '')
        ->assertSee('0 de 4 productos contados')
        ->set("counted.{$hose->id}", '3')
        ->assertSee('1 de 4 productos contados');
});

test('it saves the quantities when the browser sends the whole list at once', function () {
    ['nitrogeno' => $nitrogeno, 'bigfix' => $bigfix, 'mirabis' => $mirabis] = countPageWarehouse();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.count')
        ->call('start')
        ->set('counted', [$nitrogeno->id => '12', $bigfix->id => '14', $mirabis->id => ''])
        ->assertHasNoErrors()
        ->assertSee('2 de 3 productos contados');

    expect(PhysicalCount::query()->sole()->items()->whereNotNull('counted_quantity')->count())->toBe(2);
});

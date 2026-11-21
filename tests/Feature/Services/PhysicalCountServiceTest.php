<?php

use App\Models\AdjustmentReason;
use App\Models\InventoryMovement;
use App\Models\InventoryOperationType;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PhysicalCountService;

/**
 * Mostrador from docs/diseno/ConteoFisico.html, keyed by SKU.
 *
 * @return array{mostrador: Warehouse, products: array<string, Product>}
 */
function mostradorForCounting(): array
{
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $products = [];

    foreach (['UNI-20' => '12', 'UCA-20' => '3', 'UMG-20' => '6', 'CORE-20' => '9', 'BFX-01' => '15', 'CAL-01' => '20', 'MIR-01' => '0', 'MAT-07' => '40'] as $sku => $quantity) {
        $products[$sku] = Product::factory()->create(['sku' => $sku, 'reference_cost' => 350]);
        stockLot($products[$sku], $mostrador, $quantity, '2026-09-01');
    }

    return compact('mostrador', 'products');
}

/**
 * The count of the design: Ultra Nitrógeno short by 2, Bigfix over by 1, Mirabis and the hose not counted.
 */
function countLikeTheDesign(PhysicalCount $count, array $products): void
{
    $service = app(PhysicalCountService::class);

    foreach (['UNI-20' => '10', 'UCA-20' => '3', 'UMG-20' => '6', 'CORE-20' => '9', 'BFX-01' => '16', 'CAL-01' => '20'] as $sku => $counted) {
        $service->record($count, $products[$sku], $counted);
    }
}

test('applying the count of the design creates exactly two movements in one adjustment', function () {
    ['mostrador' => $mostrador, 'products' => $products] = mostradorForCounting();
    $service = app(PhysicalCountService::class);
    $count = $service->start(User::factory()->create(), $mostrador);
    countLikeTheDesign($count, $products);

    $applied = $service->apply(User::factory()->owner()->create(), $count);

    expect($applied->status)->toBe(PhysicalCountStatus::Applied);
    expect($applied->inventoryOperation->only(['type', 'reason']))->toBe(['type' => InventoryOperationType::Adjustment, 'reason' => AdjustmentReason::PhysicalCount->value]);
    expect(InventoryMovement::query()->where('inventory_operation_id', $applied->inventory_operation_id)->get()->map(fn ($movement) => [$movement->product_id, $movement->quantity])->all())
        ->toBe([[$products['UNI-20']->id, '-2.000'], [$products['BFX-01']->id, '1.000']]);
    expect(stockOf($products['UNI-20'], $mostrador))->toBe('10.000');
    expect(stockOf($products['BFX-01'], $mostrador))->toBe('16.000');
    expect(stockOf($products['MAT-07'], $mostrador))->toBe('40.000');
});

test('it lists every product of the warehouse and can be saved and continued later', function () {
    ['mostrador' => $mostrador, 'products' => $products] = mostradorForCounting();
    $service = app(PhysicalCountService::class);
    $count = $service->start(User::factory()->create(), $mostrador);

    $service->record($count, $products['UNI-20'], '10');

    $count = PhysicalCount::query()->sole();
    expect($count->items()->count())->toBe(8);
    expect($count->items()->whereNotNull('counted_quantity')->count())->toBe(1);
    expect($count->items()->where('product_id', $products['MIR-01']->id)->sole()->system_quantity)->toBe('0.000');
    expect(InventoryMovement::query()->where('quantity', '<', 0)->exists())->toBeFalse();
});

test('a sale made while counting is not adjusted again', function () {
    ['mostrador' => $mostrador, 'products' => $products] = mostradorForCounting();
    $service = app(PhysicalCountService::class);
    $count = $service->start(User::factory()->create(), $mostrador);
    $service->record($count, $products['CAL-01'], '18');
    app(InventoryService::class)->adjust(User::factory()->create(), $products['CAL-01'], $mostrador, '18', AdjustmentReason::Shortage);

    $applied = $service->apply(User::factory()->owner()->create(), $count);

    expect($applied->inventory_operation_id)->toBeNull();
    expect(stockOf($products['CAL-01'], $mostrador))->toBe('18.000');
});

test('there is only one count in progress per warehouse', function () {
    ['mostrador' => $mostrador] = mostradorForCounting();
    $service = app(PhysicalCountService::class);
    $count = $service->start(User::factory()->create(), $mostrador);

    expect(fn () => $service->start(User::factory()->create(), $mostrador))->toThrow(failsOn('warehouse_id'));

    $service->cancel($count);
    expect($service->start(User::factory()->create(), $mostrador)->isInProgress())->toBeTrue();
});

test('a count cannot be applied twice nor applied empty', function () {
    ['mostrador' => $mostrador, 'products' => $products] = mostradorForCounting();
    $service = app(PhysicalCountService::class);
    $count = $service->start(User::factory()->create(), $mostrador);

    expect(fn () => $service->apply(User::factory()->create(), $count))->toThrow(failsOn('count'));

    countLikeTheDesign($count, $products);
    $service->apply(User::factory()->create(), $count);

    expect(fn () => $service->apply(User::factory()->create(), $count))->toThrow(failsOn('count'));
    expect(stockOf($products['UNI-20'], $mostrador))->toBe('10.000');
});

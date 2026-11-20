<?php

use App\Models\AdjustmentReason;
use App\Models\InventoryMovement;
use App\Models\InventoryOperationType;
use App\Models\LoanEntry;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;

test('it receives consignment merchandise as a lot owned by the supplier', function () {
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $biotexa = Supplier::factory()->create(['name' => 'Biotexa']);
    $nitrogeno = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L']);
    stockLot($nitrogeno, $mostrador, '12', '2026-09-01');

    $operation = app(InventoryService::class)->receive(
        User::factory()->create(),
        $biotexa,
        $mostrador,
        [['product_id' => $nitrogeno->id, 'quantity' => '10', 'unit_cost' => '980']],
        consignment: true,
        reference: 'Nota de entrega 48',
    );

    $lot = $operation->lots()->sole();
    expect($operation->type)->toBe(InventoryOperationType::Entry);
    expect($operation->reference)->toBe('Nota de entrega 48');
    expect($lot->only(['supplier_id', 'owner_supplier_id', 'unit_cost', 'quantity_received']))->toBe([
        'supplier_id' => $biotexa->id,
        'owner_supplier_id' => $biotexa->id,
        'unit_cost' => '980.00',
        'quantity_received' => '10.000',
    ]);
    expect(stockOf($nitrogeno, $mostrador))->toBe('22.000');
    expect($operation->movements()->sole()->only(['quantity', 'stock_before', 'stock_after']))->toBe([
        'quantity' => '10.000',
        'stock_before' => '12.000',
        'stock_after' => '22.000',
    ]);
});

test('it receives bought merchandise as a lot owned by the business', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();

    $operation = app(InventoryService::class)->receive(
        User::factory()->create(),
        Supplier::factory()->create(),
        $mostrador,
        [['product_id' => $product->id, 'quantity' => '5', 'unit_cost' => '120']],
        consignment: false,
    );

    expect($operation->lots()->sole()->owner_supplier_id)->toBeNull();
    expect(stockOf($product, $mostrador))->toBe('5.000');
});

test('it requires the unit cost to receive merchandise', function () {
    $product = Product::factory()->create();

    expect(fn () => app(InventoryService::class)->receive(
        User::factory()->create(),
        Supplier::factory()->create(),
        Warehouse::factory()->create(),
        [['product_id' => $product->id, 'quantity' => '5']],
        consignment: true,
    ))->toThrow(failsOn('items'));
    expect(Lot::count())->toBe(0);
});

test('it receives into a lender warehouse at the reference cost and owned by the lender warehouse', function () {
    $francisco = Supplier::factory()->inKind()->create();
    $consigna = Warehouse::factory()->lentBy($francisco)->create();
    $product = Product::factory()->create(['reference_cost' => 640]);

    $operation = app(InventoryService::class)->receive(
        User::factory()->create(),
        $francisco,
        $consigna,
        [['product_id' => $product->id, 'quantity' => '30']],
        consignment: true,
    );

    expect($operation->lots()->sole()->only(['owner_supplier_id', 'unit_cost']))->toBe([
        'owner_supplier_id' => null,
        'unit_cost' => '640.00',
    ]);
});

test('it refuses to receive into a lender warehouse from another supplier', function () {
    $consigna = Warehouse::factory()->lentBy()->create();
    $product = Product::factory()->create();

    expect(fn () => app(InventoryService::class)->receive(
        User::factory()->create(),
        Supplier::factory()->create(),
        $consigna,
        [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '10']],
        consignment: true,
    ))->toThrow(failsOn('supplier_id'));
    expect(Lot::count())->toBe(0);
});

test('it transfers the same lots between warehouses keeping their owner', function () {
    $ortitec = Supplier::factory()->create(['name' => 'Ortitec']);
    $bodega = Warehouse::factory()->create(['name' => 'Bodega Ortitec']);
    $mostrador = Warehouse::factory()->sellable()->create();
    $core = Product::factory()->create(['name' => 'Core 20 L']);
    $ortitecStock = stockLot($core, $bodega, '10', '2026-09-15', owner: $ortitec);

    $operation = app(InventoryService::class)->transfer(User::factory()->create(), $bodega, $mostrador, [['product_id' => $core->id, 'quantity' => '4']], 'Para vender esta semana');

    expect($operation->type)->toBe(InventoryOperationType::Transfer);
    expect(stockOf($core, $bodega))->toBe('6.000');
    expect(stockOf($core, $mostrador))->toBe('4.000');
    expect($mostrador->stocks()->sole()->lot->owner_supplier_id)->toBe($ortitec->id);
    expect($operation->movements()->orderBy('id')->get(['warehouse_id', 'lot_id', 'quantity', 'stock_before', 'stock_after'])->toArray())->toBe([
        ['warehouse_id' => $bodega->id, 'lot_id' => $ortitecStock->lot_id, 'quantity' => '-4.000', 'stock_before' => '10.000', 'stock_after' => '6.000'],
        ['warehouse_id' => $mostrador->id, 'lot_id' => $ortitecStock->lot_id, 'quantity' => '4.000', 'stock_before' => '0.000', 'stock_after' => '4.000'],
    ]);
    expect(LoanEntry::count())->toBe(0);
});

test('it records a loan when product is taken from Francisco', function () {
    $francisco = Supplier::factory()->inKind()->create(['name' => 'Francisco Rodríguez']);
    $consigna = Warehouse::factory()->lentBy($francisco)->create(['name' => 'Consigna Francisco']);
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $nitrogeno = Product::factory()->create(['name' => 'Ultra Nitrógeno 20 L']);
    stockLot($nitrogeno, $consigna, '30', '2026-09-01');
    stockLot($nitrogeno, $mostrador, '9', '2026-09-10');
    $service = app(InventoryService::class);

    $operation = $service->transfer(User::factory()->create(), $consigna, $mostrador, [['product_id' => $nitrogeno->id, 'quantity' => '5']], 'Reponer con el siguiente envío');

    expect(stockOf($nitrogeno, $consigna))->toBe('25.000');
    expect(stockOf($nitrogeno, $mostrador))->toBe('14.000');
    expect($operation->loanEntries()->sole()->only(['lender_id', 'product_id', 'quantity', 'note']))->toBe([
        'lender_id' => $francisco->id,
        'product_id' => $nitrogeno->id,
        'quantity' => '5.000',
        'note' => 'Reponer con el siguiente envío',
    ]);
    expect($service->pendingLoans($francisco)->sole()->pending_quantity)->toBe('5.000');
});

test('it rejects invalid transfers without moving stock', function (string $toKey, string $note, string $field) {
    $origin = Warehouse::factory()->create();
    $destinations = [
        'other' => Warehouse::factory()->sellable()->create(),
        'same' => $origin,
        'lender' => Warehouse::factory()->lentBy()->create(),
    ];
    $product = Product::factory()->create();
    stockLot($product, $origin, '10', '2026-09-01');

    expect(fn () => app(InventoryService::class)->transfer(User::factory()->create(), $origin, $destinations[$toKey], [['product_id' => $product->id, 'quantity' => '4']], $note))
        ->toThrow(failsOn($field));
    expect(stockOf($product, $origin))->toBe('10.000');
    expect(InventoryMovement::count())->toBe(0);
})->with([
    'without a note' => ['other', '', 'note'],
    'to the same warehouse' => ['same', 'Nota', 'to_warehouse_id'],
    'into a lender warehouse' => ['lender', 'Nota', 'to_warehouse_id'],
]);

test('it refuses to transfer more than the origin holds', function () {
    $origin = Warehouse::factory()->create();
    $destination = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    stockLot($product, $origin, '3', '2026-09-01');

    expect(fn () => app(InventoryService::class)->transfer(User::factory()->create(), $origin, $destination, [['product_id' => $product->id, 'quantity' => '4']], 'Nota'))
        ->toThrow(failsOn('items'));
    expect(stockOf($product, $origin))->toBe('3.000');
    expect(stockOf($product, $destination))->toBe('0.000');
    expect(InventoryMovement::count())->toBe(0);
});

test('it repays a loan in parts until it is settled', function () {
    $francisco = Supplier::factory()->inKind()->create();
    $consigna = Warehouse::factory()->lentBy($francisco)->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $magnesio = Product::factory()->create(['name' => 'Ultra Magnesio 20 L']);
    stockLot($magnesio, $consigna, '10', '2026-09-01');
    stockLot($magnesio, $mostrador, '6', '2026-09-20');
    $service = app(InventoryService::class);
    $user = User::factory()->create();
    $service->transfer($user, $consigna, $mostrador, [['product_id' => $magnesio->id, 'quantity' => '4']], 'Préstamo');
    $loan = LoanEntry::query()->sole();

    $service->repayLoan($user, $loan, '2', $mostrador);

    expect($service->pendingQuantity($loan))->toBe('2.000');

    $operation = $service->repayLoan($user, $loan, '2', $mostrador, 'Repuso con el envío de Biotexa');

    expect($service->pendingQuantity($loan))->toBe('0.000');
    expect($service->pendingLoans($francisco))->toBeEmpty();
    expect($operation->loanEntries()->sole()->only(['repays_loan_entry_id', 'quantity']))->toBe([
        'repays_loan_entry_id' => $loan->id,
        'quantity' => '-2.000',
    ]);
    expect(stockOf($magnesio, $mostrador))->toBe('6.000');
    expect(stockOf($magnesio, $consigna))->toBe('10.000');
});

test('it refuses to repay more than the loan still owes', function () {
    $consigna = Warehouse::factory()->lentBy()->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    stockLot($product, $consigna, '10', '2026-09-01');
    $service = app(InventoryService::class);
    $service->transfer(User::factory()->create(), $consigna, $mostrador, [['product_id' => $product->id, 'quantity' => '3']], 'Préstamo');

    expect(fn () => $service->repayLoan(User::factory()->create(), LoanEntry::query()->sole(), '4', $mostrador))
        ->toThrow(failsOn('quantity'));
    expect(stockOf($product, $mostrador))->toBe('3.000');
});

test('it repays only with stock the business owns', function () {
    $consigna = Warehouse::factory()->lentBy()->create();
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    stockLot($product, $consigna, '10', '2026-09-01');
    $service = app(InventoryService::class);
    $service->transfer(User::factory()->create(), $consigna, $mostrador, [['product_id' => $product->id, 'quantity' => '3']], 'Préstamo');
    $service->exit(User::factory()->create(), $mostrador, [['product_id' => $product->id, 'quantity' => '3']], 'Venta de mostrador', 'Encargada');
    stockLot($product, $mostrador, '5', '2026-10-01', owner: Supplier::factory()->create());

    expect(fn () => $service->repayLoan(User::factory()->create(), LoanEntry::query()->sole(), '3', $mostrador))
        ->toThrow(failsOn('items'));
    expect(stockOf($product, $mostrador))->toBe('5.000');
});

test('it records an exit with its reason and who took it', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    stockLot($product, $mostrador, '10', '2026-09-01');

    $operation = app(InventoryService::class)->exit(User::factory()->create(), $mostrador, [['product_id' => $product->id, 'quantity' => '1']], 'Muestra', 'Ing. Meléndez', 'Para un experimento en parcela');

    expect($operation->only(['reason', 'responsible', 'note']))->toBe([
        'reason' => 'Muestra',
        'responsible' => 'Ing. Meléndez',
        'note' => 'Para un experimento en parcela',
    ]);
    expect($operation->movements()->sole()->only(['quantity', 'stock_before', 'stock_after']))->toBe([
        'quantity' => '-1.000',
        'stock_before' => '10.000',
        'stock_after' => '9.000',
    ]);
});

test('it requires a reason and who took the product for an exit', function (string $reason, string $responsible) {
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    stockLot($product, $mostrador, '10', '2026-09-01');

    expect(fn () => app(InventoryService::class)->exit(User::factory()->create(), $mostrador, [['product_id' => $product->id, 'quantity' => '1']], $reason, $responsible))
        ->toThrow(failsOn('reason'));
    expect(stockOf($product, $mostrador))->toBe('10.000');
})->with([
    'without reason' => ['', 'Ing. Meléndez'],
    'without responsible' => ['Muestra', ''],
]);

test('it adjusts a shortage found in a physical count', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $nitrogeno = Product::factory()->create();
    stockLot($nitrogeno, $mostrador, '12', '2026-09-01');

    $operation = app(InventoryService::class)->adjust(User::factory()->create(), $nitrogeno, $mostrador, '10', AdjustmentReason::Shortage, 'Conteo físico');

    expect($operation->type)->toBe(InventoryOperationType::Adjustment);
    expect($operation->reason)->toBe('shortage');
    expect($operation->movements()->sole()->only(['quantity', 'stock_before', 'stock_after']))->toBe([
        'quantity' => '-2.000',
        'stock_before' => '12.000',
        'stock_after' => '10.000',
    ]);
});

test('it adds a surplus as a new lot of the business at the reference cost', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create(['reference_cost' => 700]);
    stockLot($product, $mostrador, '5', '2026-09-01', owner: Supplier::factory()->create());

    $operation = app(InventoryService::class)->adjust(User::factory()->create(), $product, $mostrador, '6', AdjustmentReason::Surplus);

    expect($operation->lots()->sole()->only(['supplier_id', 'owner_supplier_id', 'unit_cost', 'quantity_received']))->toBe([
        'supplier_id' => null,
        'owner_supplier_id' => null,
        'unit_cost' => '700.00',
        'quantity_received' => '1.000',
    ]);
    expect(stockOf($product, $mostrador))->toBe('6.000');
});

test('it rejects an adjustment without a difference or with a reason that contradicts it', function (string $counted, AdjustmentReason $reason, string $field) {
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    stockLot($product, $mostrador, '12', '2026-09-01');

    expect(fn () => app(InventoryService::class)->adjust(User::factory()->create(), $product, $mostrador, $counted, $reason))
        ->toThrow(failsOn($field));
    expect(stockOf($product, $mostrador))->toBe('12.000');
})->with([
    'count matches the system' => ['12', AdjustmentReason::Shortage, 'counted_quantity'],
    'surplus counted as shortage' => ['14', AdjustmentReason::Shortage, 'reason'],
    'shortage counted as surplus' => ['10', AdjustmentReason::Surplus, 'reason'],
]);

test('it lists the kardex of a product in a warehouse, newest first', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $bodega = Warehouse::factory()->create();
    $product = Product::factory()->create();
    stockLot($product, $bodega, '10', '2026-09-01');
    $service = app(InventoryService::class);
    $user = User::factory()->create();
    $service->transfer($user, $bodega, $mostrador, [['product_id' => $product->id, 'quantity' => '4']], 'Para vender');
    $service->exit($user, $mostrador, [['product_id' => $product->id, 'quantity' => '1']], 'Muestra', 'Ing. Meléndez');

    $kardex = $service->kardex($product, $mostrador)->get();

    expect($kardex->map->only(['quantity', 'stock_before', 'stock_after'])->all())->toBe([
        ['quantity' => '-1.000', 'stock_before' => '4.000', 'stock_after' => '3.000'],
        ['quantity' => '4.000', 'stock_before' => '0.000', 'stock_after' => '4.000'],
    ]);
});

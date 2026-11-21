<?php

use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;

test('the database refuses a negative stock', function () {
    $stock = stockLot(Product::factory()->create(), Warehouse::factory()->create(), '3', '2026-10-01');

    expect(fn () => $stock->update(['quantity' => '-1']))->toThrow(QueryException::class);
    expect($stock->fresh()->quantity)->toBe('3.000');
});

test('a sale with items or payments cannot be deleted', function () {
    $sale = Sale::factory()->create();
    SaleItem::factory()->for($sale)->create();

    expect(fn () => $sale->delete())->toThrow(QueryException::class);
    expect(Sale::query()->whereKey($sale->id)->exists())->toBeTrue();
});

test('invoice items keep quantities with three decimals', function () {
    $item = InvoiceItem::factory()->create(['quantity' => '2.375']);

    expect($item->fresh()->quantity)->toBe('2.375');
});

<?php

use App\Models\Lot;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Expectation for toThrow(): a ValidationException with an error on the given field.
 */
function failsOn(string $field): Closure
{
    return fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field);
}

/**
 * Puts a lot of the product in the warehouse, owned by the business unless an owner is given.
 */
function stockLot(Product $product, Warehouse $warehouse, string $quantity, string $receivedAt, ?Supplier $owner = null): Stock
{
    $lot = Lot::factory()->for($product)->create([
        'quantity_received' => $quantity,
        'received_at' => $receivedAt,
        'owner_supplier_id' => $owner?->id,
    ]);

    return Stock::factory()->for($lot)->for($warehouse)->create(['product_id' => $product->id, 'quantity' => $quantity]);
}

/**
 * Total stock of a product in a warehouse, as a 3-decimal string.
 */
function stockOf(Product $product, Warehouse $warehouse): string
{
    $total = Stock::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sum('quantity');

    return number_format((float) $total, 3, '.', '');
}

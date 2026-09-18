<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));
});

test('products page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('products.index'))->assertOk();
});

test('a product can be created', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products')
        ->call('create')
        ->set('name', 'Insecticida Cipermetrina 25 CE')
        ->set('sku', 'AGR-0001')
        ->set('unit_price', '450.00')
        ->set('tax_rate', '0.16')
        ->set('product_key', '10191509')
        ->set('unit_key', 'LTR')
        ->set('unit_name', 'Litro')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('products', [
        'sku' => 'AGR-0001',
        'name' => 'Insecticida Cipermetrina 25 CE',
    ]);
});

test('a product can be created without a price yet', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products')
        ->call('create')
        ->set('name', 'Producto Pendiente de Precio')
        ->set('sku', 'AGR-0099')
        ->set('product_key', '10191509')
        ->set('unit_key', 'LTR')
        ->set('unit_name', 'Litro')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('products', [
        'sku' => 'AGR-0099',
        'unit_price' => null,
    ]);
});

test('the product list shows a badge for products without a price', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->withoutPrice()->create(['name' => 'Sin Precio Aún']);

    Livewire::test('pages::products')
        ->assertSee('Sin Precio Aún')
        ->assertSee('Sin precio');
});

test('creating a product requires the fiscal fields', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products')
        ->call('create')
        ->set('name', '')
        ->set('sku', '')
        ->set('product_key', '')
        ->set('unit_key', '')
        ->set('unit_name', '')
        ->call('save')
        ->assertHasErrors(['name', 'sku', 'product_key', 'unit_key', 'unit_name']);
});

test('a product can be updated', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['name' => 'Original']);

    Livewire::test('pages::products')
        ->call('edit', $product->id)
        ->set('name', 'Updated name')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->name)->toBe('Updated name');
});

test('a product can be deleted', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create();

    Livewire::test('pages::products')
        ->call('confirmDelete', $product->id)
        ->call('delete');

    expect(Product::find($product->id))->toBeNull();
});

test('searching the SAT product catalog fills the key and its description', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('searchProductKeys')->with('insecticida')->once()->andReturn([
            ['key' => '10191509', 'description' => 'Insecticidas'],
        ]);
    });

    Livewire::test('pages::products')
        ->call('create')
        ->set('product_key_search', 'insecticida')
        ->assertSet('product_key_results', [['key' => '10191509', 'description' => 'Insecticidas']])
        ->call('selectProductKey', '10191509')
        ->assertSet('product_key', '10191509')
        ->assertSet('product_key_name', 'Insecticidas')
        ->assertSet('product_key_results', []);
});

test('searching the SAT unit catalog fills the key and its name', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldReceive('searchUnitKeys')->with('litro')->once()->andReturn([
            ['key' => 'LTR', 'description' => 'Litro'],
        ]);
    });

    Livewire::test('pages::products')
        ->call('create')
        ->set('unit_key_search', 'litro')
        ->call('selectUnitKey', 'LTR')
        ->assertSet('unit_key', 'LTR')
        ->assertSet('unit_name', 'Litro');
});

test('a short catalog search does not call facturapi', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(FacturapiGateway::class, function ($mock) {
        $mock->shouldNotReceive('searchProductKeys');
    });

    Livewire::test('pages::products')
        ->call('create')
        ->set('product_key_search', 'in')
        ->assertSet('product_key_results', []);
});

test('the product list can be searched by name, sku or barcode', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->create(['name' => 'Insecticida X', 'sku' => 'AAA-1', 'barcode' => '1111111111111']);
    Product::factory()->create(['name' => 'Fertilizante Y', 'sku' => 'BBB-2', 'barcode' => '2222222222222']);

    Livewire::test('pages::products')
        ->set('search', '2222222222222')
        ->assertSee('Fertilizante Y')
        ->assertDontSee('Insecticida X');
});

test('scanning a known barcode opens the product for editing', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['barcode' => '7501234567890']);

    Livewire::test('pages::products')
        ->set('barcodeScan', '7501234567890')
        ->call('scanBarcode')
        ->assertSet('editingId', $product->id)
        ->assertSet('name', $product->name);
});

test('scanning an unknown barcode shows an error and opens nothing', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products')
        ->set('barcodeScan', 'does-not-exist')
        ->call('scanBarcode')
        ->assertSet('editingId', null);
});

test('typing a taken sku marks it as unavailable, and a free one as available', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->create(['sku' => 'TAKEN-1']);

    Livewire::test('pages::products')
        ->call('create')
        ->set('sku', 'TAKEN-1')
        ->assertSet('skuAvailable', false)
        ->set('sku', 'FREE-1')
        ->assertSet('skuAvailable', true);
});

test('editing a product does not flag its own sku as taken', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['sku' => 'SELF-1']);

    Livewire::test('pages::products')
        ->call('edit', $product->id)
        ->set('sku', 'SELF-1')
        ->assertSet('skuAvailable', true);
});

test('the price with tax preview updates as price and tax rate change', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products')
        ->call('create')
        ->set('unit_price', '100')
        ->set('tax_rate', '0.16')
        ->assertSee('116.00');
});

test('the sat key fields can be cleared', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create();

    Livewire::test('pages::products')
        ->call('edit', $product->id)
        ->assertSet('product_key', $product->product_key)
        ->call('clearProductKey')
        ->assertSet('product_key', '')
        ->assertSet('product_key_name', '')
        ->call('clearUnitKey')
        ->assertSet('unit_key', '')
        ->assertSet('unit_name', '');
});

test('save and add another persists the product and leaves the form ready for a new one', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products')
        ->call('create')
        ->set('name', 'Producto Uno')
        ->set('sku', 'BATCH-1')
        ->set('unit_price', '100')
        ->set('tax_rate', '0.16')
        ->set('product_key', '10191509')
        ->set('unit_key', 'LTR')
        ->set('unit_name', 'Litro')
        ->call('saveAndAddAnother')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertSet('sku', '');

    $this->assertDatabaseHas('products', ['sku' => 'BATCH-1']);
});

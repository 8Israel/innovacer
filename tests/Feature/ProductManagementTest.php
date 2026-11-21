<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('products.create'))->assertRedirect(route('login'));
});

test('the product form is displayed for a new product and for an existing one', function () {
    $this->actingAs(User::factory()->create());
    $product = Product::factory()->create(['name' => 'Ultra Calcio 20 L']);

    $this->get(route('products.create'))->assertOk()->assertSeeInOrder(['Nuevo producto', 'Identificación', 'Precios', 'Datos para facturar', 'Existencia mínima por almacén', 'Etiqueta', '¿Y la existencia?']);
    $this->get(route('products.edit', $product))->assertOk()->assertSee('Ultra Calcio 20 L')->assertSee('Imprimir etiquetas');
});

test('a product can be created', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products.form')
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

    Livewire::test('pages::products.form')
        ->set('name', 'Producto Pendiente de Precio')
        ->set('sku', 'AGR-0099')
        ->set('tax_rate', '0.16')
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

    Livewire::test('pages::inventory.index')
        ->assertSee('Sin Precio Aún')
        ->assertSee('Sin precio');
});

test('creating a product requires a name, a code and the IVA rate, but not the SAT keys', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products.form')
        ->set('name', '')
        ->set('sku', '')
        ->set('product_key', '')
        ->set('unit_key', '')
        ->set('unit_name', '')
        ->call('save')
        ->assertHasErrors(['name', 'sku', 'tax_rate'])
        ->assertHasNoErrors(['product_key', 'unit_key', 'unit_name']);
});

test('a product is saved with its supplier, wholesale price and minimum stock by warehouse', function () {
    $this->actingAs(User::factory()->create());
    $biotexa = Supplier::factory()->create(['name' => 'Biotexa']);
    $category = Category::factory()->create(['name' => 'Fertilizantes']);
    $mostrador = Warehouse::factory()->sellable()->create();
    $bodega = Warehouse::factory()->create();

    Livewire::test('pages::products.form')
        ->set('name', 'Ultra Calcio 20 L')
        ->set('sku', 'UCA-20')
        ->set('brand', 'Biotexa')
        ->set('presentation', '20 L')
        ->set('unit', 'garrafa')
        ->set('supplier_id', $biotexa->id)
        ->set('category_id', $category->id)
        ->set('reference_cost', '1050')
        ->set('unit_price', '1320')
        ->set('wholesale_price', '1250')
        ->set('wholesale_min_quantity', '10')
        ->set('tax_rate', '0')
        ->set("minimums.{$mostrador->id}", '5')
        ->set("minimums.{$bodega->id}", '')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::query()->where('sku', 'UCA-20')->sole();
    expect($product->only(['supplier_id', 'category_id', 'unit', 'reference_cost', 'wholesale_price', 'wholesale_min_quantity', 'tax_rate', 'product_key', 'ieps_rate']))->toBe([
        'supplier_id' => $biotexa->id,
        'category_id' => $category->id,
        'unit' => 'garrafa',
        'reference_cost' => '1050.00',
        'wholesale_price' => '1250.00',
        'wholesale_min_quantity' => '10.000',
        'tax_rate' => '0.0000',
        'product_key' => null,
        'ieps_rate' => null,
    ]);
    expect($product->stockMinimums()->pluck('min_quantity', 'warehouse_id')->all())->toBe([$mostrador->id => '5.000']);
});

test('editing a product loads its minimum stock and removes a cleared one', function () {
    $this->actingAs(User::factory()->create());
    $mostrador = Warehouse::factory()->sellable()->create();
    $product = Product::factory()->create();
    StockMinimum::factory()->for($product)->for($mostrador)->create(['min_quantity' => 5]);

    Livewire::test('pages::products.form', ['product' => $product])
        ->assertSet("minimums.{$mostrador->id}", '5')
        ->set("minimums.{$mostrador->id}", '')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->stockMinimums()->exists())->toBeFalse();
});

test('it generates a valid internal barcode that no other product has', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::products.form')
        ->call('generateBarcode');

    $barcode = $component->get('barcode');
    $digits = array_map('intval', str_split(substr($barcode, 0, 12)));
    $checksum = (10 - array_sum(array_map(fn ($digit, $position) => $digit * ($position % 2 === 0 ? 1 : 3), $digits, array_keys($digits))) % 10) % 10;

    expect($barcode)->toStartWith('200')->toHaveLength(13);
    expect((int) $barcode[12])->toBe($checksum);
    $component->assertSet('barcodeAvailable', true);
});

test('a product with movements is deactivated instead of deleted', function () {
    $this->actingAs(User::factory()->create());
    $product = Product::factory()->create();
    stockLot($product, Warehouse::factory()->create(), '5', '2026-10-01');

    Livewire::test('pages::products.form', ['product' => $product])
        ->call('delete');

    expect($product->fresh()->is_active)->toBeFalse();
});

test('a product can be updated', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['name' => 'Original']);

    Livewire::test('pages::products.form', ['product' => $product])
        ->set('name', 'Updated name')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->name)->toBe('Updated name');
});

test('a product can be deleted', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create();

    Livewire::test('pages::products.form', ['product' => $product])
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

    Livewire::test('pages::products.form')
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

    Livewire::test('pages::products.form')
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

    Livewire::test('pages::products.form')
        ->set('product_key_search', 'in')
        ->assertSet('product_key_results', []);
});

test('the product list can be searched by name, sku or barcode', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->create(['name' => 'Insecticida X', 'sku' => 'AAA-1', 'barcode' => '1111111111111']);
    Product::factory()->create(['name' => 'Fertilizante Y', 'sku' => 'BBB-2', 'barcode' => '2222222222222']);

    Livewire::test('pages::inventory.index')
        ->set('search', '2222222222222')
        ->assertSee('Fertilizante Y')
        ->assertDontSee('Insecticida X');
});

test('scanning a known barcode in the inventory finds the product with its edit link', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['barcode' => '7501234567890']);

    Livewire::test('pages::inventory.index')
        ->set('search', '7501234567890')
        ->assertSee($product->name)
        ->assertSee(route('products.edit', $product), escape: false);
});

test('scanning an unknown barcode in the inventory finds nothing', function () {
    $this->actingAs(User::factory()->create());
    Product::factory()->create(['barcode' => '7501234567890']);

    Livewire::test('pages::inventory.index')
        ->set('search', 'does-not-exist')
        ->assertSee('No hay productos con esos filtros.');
});

test('a deactivated product can be found among the inactive ones and reactivated', function () {
    $this->actingAs(User::factory()->create());
    $product = Product::factory()->create(['name' => 'Producto Viejo', 'is_active' => false]);

    Livewire::test('pages::inventory.index')
        ->assertDontSee('Producto Viejo')
        ->set('show_inactive', true)
        ->assertSee('Producto Viejo');

    Livewire::test('pages::products.form', ['product' => $product])
        ->set('is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->is_active)->toBeTrue();
});

test('typing a taken sku marks it as unavailable, and a free one as available', function () {
    $this->actingAs(User::factory()->create());

    Product::factory()->create(['sku' => 'TAKEN-1']);

    Livewire::test('pages::products.form')
        ->set('sku', 'TAKEN-1')
        ->assertSet('skuAvailable', false)
        ->set('sku', 'FREE-1')
        ->assertSet('skuAvailable', true);
});

test('editing a product does not flag its own sku as taken', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create(['sku' => 'SELF-1']);

    Livewire::test('pages::products.form', ['product' => $product])
        ->set('sku', 'SELF-1')
        ->assertSet('skuAvailable', true);
});

test('the price with tax preview updates as price and tax rate change', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::products.form')
        ->set('unit_price', '100')
        ->set('tax_rate', '0.16')
        ->assertSee('116.00');
});

test('the sat key fields can be cleared', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create();

    Livewire::test('pages::products.form', ['product' => $product])
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

    Livewire::test('pages::products.form')
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

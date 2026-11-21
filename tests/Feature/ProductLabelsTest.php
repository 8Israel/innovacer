<?php

use App\Models\Product;
use App\Models\User;
use App\Services\Ean13;
use Livewire\Livewire;

test('it accepts valid EAN-13 and UPC-A codes and rejects the rest', function () {
    $ean13 = app(Ean13::class);

    expect($ean13->normalize('4006381333931'))->toBe('4006381333931');
    expect($ean13->normalize('5901234123457'))->toBe('5901234123457');
    expect($ean13->normalize('036000291452'))->toBe('0036000291452');
    expect($ean13->normalize('4006381333932'))->toBeNull();
    expect($ean13->normalize('BFX-01'))->toBeNull();
    expect($ean13->normalize(null))->toBeNull();
});

test('the bars follow the EAN-13 structure', function () {
    $modules = app(Ean13::class)->modules('4006381333931');
    $digitParity = fn (string $pattern) => substr_count($pattern, '1') % 2 === 1 ? 'O' : 'E';
    $left = collect(str_split(substr($modules, 3, 42), 7))->map($digitParity)->join('');
    $right = collect(str_split(substr($modules, 50, 42), 7))->map($digitParity)->join('');

    expect(strlen($modules))->toBe(95);
    expect([substr($modules, 0, 3), substr($modules, 45, 5), substr($modules, 92)])->toBe(['101', '01010', '101']);
    expect($left)->toBe('OEOOEE');
    expect($right)->toBe('EEEEEE');
});

test('internal codes are valid EAN-13 starting with 200', function () {
    $code = Product::newInternalBarcode();

    expect($code)->toStartWith('200');
    expect(app(Ean13::class)->normalize($code))->toBe($code);
});

test('the labels show the name, the price with taxes and the bars, one per copy', function () {
    $calcio = Product::factory()->create(['name' => 'Calcio 1 L', 'sku' => 'CAL-01', 'barcode' => '2001234567893', 'unit_price' => 185, 'tax_rate' => 0.16, 'ieps_rate' => null]);
    $hose = Product::factory()->create(['name' => 'Manguera de riego 16 mm', 'barcode' => null, 'unit_price' => null]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('products.labels', ['p' => [$calcio->id => 3, $hose->id => 1], 'formato' => 'carta']))
        ->assertOk()
        ->assertSee('4 etiquetas · Hoja carta (3 × 10)')
        ->assertSeeInOrder(['Calcio 1 L', '$214.60', 'aria-label="2001234567893"', 'CAL-01'], escape: false)
        ->assertSee('Sin código EAN-13: genera un código interno');

    expect(substr_count($response->getContent(), 'Calcio 1 L'))->toBe(3);
});

test('it generates internal codes for the chosen products that have none', function () {
    $hose = Product::factory()->create(['name' => 'Manguera de riego 16 mm', 'barcode' => null]);
    $withCode = Product::factory()->create(['barcode' => '7501000000018']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.labels')
        ->call('add', $hose->id)
        ->call('add', $withCode->id)
        ->call('add', $hose->id)
        ->assertSet("selected.{$hose->id}", 2)
        ->call('generateCodes')
        ->assertSee('Imprimir 3 etiquetas');

    expect($hose->fresh()->barcode)->toStartWith('200');
    expect($withCode->fresh()->barcode)->toBe('7501000000018');
});

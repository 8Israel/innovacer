<?php

use App\Models\Lot;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductImportService;
use App\Services\XlsxFile;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/**
 * Rows like those of docs/diseno/Importar.html, with the template headers.
 *
 * @return list<list<string>>
 */
function importSheet(): array
{
    $row = fn (array $values) => array_values(array_merge(array_fill_keys(array_keys(ProductImportService::COLUMNS), ''), $values));

    return [
        array_values(ProductImportService::COLUMNS),
        $row(['sku' => 'MIR-01', 'name' => 'Mirabis 1 L', 'barcode' => '7501000000018', 'unit_price' => '390', 'tax_rate' => '16', 'reference_cost' => '250', 'supplier' => 'Coda', 'stock' => '12']),
        $row(['sku' => 'MIR-01B', 'name' => 'Mirabis 1 L caja', 'barcode' => '7501000000018', 'unit_price' => '4,500.00', 'tax_rate' => '16', 'stock' => '2']),
        $row(['sku' => 'MAT-07', 'name' => 'Manguera de riego 16 mm', 'tax_rate' => '16', 'barcode' => '7501000000025', 'reference_cost' => '12', 'supplier' => 'Coda', 'stock' => '40']),
        $row(['sku' => 'CORE-20', 'name' => 'Core 20 L', 'barcode' => '7501000000032', 'unit_price' => '$2,100', 'tax_rate' => '0', 'reference_cost' => '1600', 'supplier' => 'ortitec', 'consignment' => 'Sí', 'stock' => '9', 'minimum' => '3']),
        $row([]),
    ];
}

beforeEach(function () {
    Supplier::factory()->create(['name' => 'Coda']);
    Supplier::factory()->create(['name' => 'Ortitec']);
});

test('the template is an xlsx with the headers that the import reads back', function () {
    $xlsx = app(XlsxFile::class);
    $path = tempnam(sys_get_temp_dir(), 'tpl');
    file_put_contents($path, app(ProductImportService::class)->template());

    $read = $xlsx->read($path, 'plantilla-productos.xlsx', 'Productos');

    expect($read['sheet'])->toBe('Productos');
    expect($read['rows'][0])->toBe(array_values(ProductImportService::COLUMNS));
    expect($read['rows'][1][0])->toBe('UNI-20');
});

test('each row is checked: repeated barcodes are errors and missing data are warnings', function () {
    $analysis = app(ProductImportService::class)->analyze(importSheet());

    expect(collect($analysis['rows'])->map(fn (array $row) => [$row['line'], $row['errors'], $row['warnings']])->all())->toBe([
        [2, ['Código de barras repetido con la fila 3.'], []],
        [3, ['Código de barras repetido con la fila 2.'], ['Sin proveedor: se importa sin proveedor.', 'Sin costo: su utilidad no se podrá calcular.']],
        [4, [], ['Sin precio: se importa como no vendible.']],
        [5, [], []],
    ]);
});

test('it imports the valid rows with their stock, owner and minimum', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $sheet = importSheet();
    $sheet[2][array_search('barcode', array_keys(ProductImportService::COLUMNS), true)] = '';

    $result = app(ProductImportService::class)->import(User::factory()->create(), $mostrador, $sheet, 'productos_innovacer.xlsx');

    expect($result)->toBe(['products' => 4, 'with_stock' => 4, 'skipped' => 0]);
    $core = Product::query()->where('sku', 'CORE-20')->sole();
    expect($core->only(['unit_price', 'tax_rate', 'reference_cost']))->toBe(['unit_price' => '2100.00', 'tax_rate' => '0.0000', 'reference_cost' => '1600.00']);
    expect(stockOf($core, $mostrador))->toBe('9.000');
    expect(Lot::query()->where('product_id', $core->id)->sole()->owner_supplier_id)->toBe(Supplier::query()->where('name', 'Ortitec')->value('id'));
    expect(StockMinimum::query()->where('product_id', $core->id)->sole()->min_quantity)->toBe('3.000');
    expect(Product::query()->where('sku', 'MAT-07')->sole()->unit_price)->toBeNull();
    expect(Product::query()->where('sku', 'MIR-01B')->sole()->barcode)->toStartWith('200');
});

test('importing the same file twice does not duplicate products nor stock', function () {
    $mostrador = Warehouse::factory()->sellable()->create();
    $service = app(ProductImportService::class);
    $service->import(User::factory()->create(), $mostrador, importSheet(), 'productos.xlsx');

    $again = $service->import(User::factory()->create(), $mostrador, importSheet(), 'productos.xlsx');

    expect($again['products'])->toBe(0);
    expect(Product::query()->where('sku', 'CORE-20')->count())->toBe(1);
    expect(stockOf(Product::query()->where('sku', 'CORE-20')->sole(), $mostrador))->toBe('9.000');
});

test('the import page reviews an uploaded workbook and imports it', function () {
    $mostrador = Warehouse::factory()->sellable()->create(['name' => 'Mostrador Innovacer']);
    $file = UploadedFile::fake()->createWithContent('productos_innovacer.xlsx', app(XlsxFile::class)->write('Productos', importSheet()));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.import')
        ->set('file', $file)
        ->assertHasNoErrors()
        ->assertSee('productos_innovacer.xlsx')
        ->assertSee('repetido con la fila 3')
        ->assertSee('Importar 2 productos')
        ->set('only_problems', true)
        ->assertDontSee('CORE-20')
        ->call('import')
        ->assertSee('2 productos importados');

    expect(Product::query()->pluck('sku')->sort()->values()->all())->toBe(['CORE-20', 'MAT-07']);
    expect(stockOf(Product::query()->where('sku', 'MAT-07')->sole(), $mostrador))->toBe('40.000');
});

test('a file without the required columns is rejected with a clear message', function () {
    $file = UploadedFile::fake()->createWithContent('otra.xlsx', app(XlsxFile::class)->write('Hoja1', [['Producto', 'Cantidad'], ['Core', '3']]));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::inventory.import')
        ->set('file', $file)
        ->assertSee('Al archivo le faltan columnas')
        ->assertSee('Clave, Nombre');
});

test('it reads workbooks saved by Excel, with shared strings, numbers and skipped cells', function () {
    $path = tempnam(sys_get_temp_dir(), 'xl');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Notas" sheetId="1" r:id="rId1"/><sheet name="Productos" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="worksheet" Target="/xl/worksheets/sheet2.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Clave</t></si><si><t>Nombre</t></si><si><r><t>Calcio </t></r><r><t>1 L</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
    $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        .'<row r="2"><c r="A2"><v>1001</v></c><c r="B2" t="s"><v>2</v></c><c r="D2"><v>185.5</v></c></row>'
        .'</sheetData></worksheet>');
    $zip->close();

    $read = app(XlsxFile::class)->read($path, 'productos.xlsx', 'Productos');

    expect($read)->toBe(['sheet' => 'Productos', 'rows' => [['Clave', 'Nombre'], ['1001', 'Calcio 1 L', '', '185.5']]]);
});

<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Initial load of products and their stock from Excel (RF-06, RNF-07,
 * docs/diseno/Importar.html). Every row is checked first: rows with an error are
 * skipped, rows with a warning are imported as they are. Products already in the
 * system (same code) are not touched, so importing the same file twice does not
 * duplicate stock.
 */
class ProductImportService
{
    /**
     * Template columns: field => header.
     */
    public const COLUMNS = [
        'sku' => 'Clave',
        'name' => 'Nombre',
        'brand' => 'Marca',
        'presentation' => 'Presentación',
        'unit' => 'Unidad',
        'barcode' => 'Código de barras',
        'category' => 'Categoría',
        'supplier' => 'Proveedor',
        'consignment' => 'En consigna (sí/no)',
        'reference_cost' => 'Costo',
        'unit_price' => 'Precio',
        'wholesale_price' => 'Precio mayoreo',
        'wholesale_min_quantity' => 'Mayoreo desde',
        'tax_rate' => 'IVA %',
        'ieps_rate' => 'IEPS %',
        'product_key' => 'Clave SAT',
        'unit_key' => 'Unidad SAT',
        'stock' => 'Existencia',
        'minimum' => 'Mínimo',
    ];

    private const REQUIRED = ['sku', 'name'];

    public function __construct(
        private InventoryService $inventory,
        private XlsxFile $xlsx,
    ) {}

    /**
     * The template to fill in, with one example row.
     */
    public function template(): string
    {
        return $this->xlsx->write('Productos', [
            array_values(self::COLUMNS),
            ['UNI-20', 'Ultra Nitrógeno 20 L', 'Biotexa', '20 L', 'pieza', '7501234567890', 'Fertilizantes', 'Biotexa', 'no', '980', '1250', '1180', '10', '0', '', '10171500', 'H87', '12', '3'],
        ]);
    }

    /**
     * Check every row of the sheet, the first being the headers.
     *
     * @param  list<list<string>>  $sheet
     * @return array{missing_columns: list<string>, rows: list<array{line: int, data: array<string, string>, errors: list<string>, warnings: list<string>}>}
     */
    public function analyze(array $sheet): array
    {
        $headers = array_map(fn (string $header) => $this->normalize($header), $sheet[0] ?? []);
        $positions = [];

        foreach (self::COLUMNS as $field => $header) {
            $position = array_search($this->normalize($header), $headers, true);

            if ($position !== false) {
                $positions[$field] = $position;
            }
        }

        $missing = array_values(array_map(fn (string $field) => self::COLUMNS[$field], array_diff(self::REQUIRED, array_keys($positions))));

        if ($missing !== []) {
            return ['missing_columns' => $missing, 'rows' => []];
        }

        $rows = collect(array_slice($sheet, 1, preserve_keys: true))
            ->map(fn (array $cells, int $index) => [
                'line' => $index + 1,
                'data' => array_map(fn (int $position) => trim((string) ($cells[$position] ?? '')), $positions) + array_fill_keys(array_keys(self::COLUMNS), ''),
            ])
            ->reject(fn (array $row) => collect($row['data'])->every(fn (string $value) => $value === ''))
            ->values();

        $skus = $rows->groupBy(fn (array $row) => mb_strtoupper($row['data']['sku']));
        $barcodes = $rows->filter(fn (array $row) => $row['data']['barcode'] !== '')->groupBy(fn (array $row) => $row['data']['barcode']);
        $existingSkus = Product::query()->whereIn('sku', $rows->pluck('data.sku')->filter())->pluck('sku')->map(fn (string $sku) => mb_strtoupper($sku))->all();
        $existingBarcodes = Product::query()->whereIn('barcode', $barcodes->keys())->pluck('barcode')->all();
        $suppliers = Supplier::query()->get()->keyBy(fn (Supplier $supplier) => $this->normalize($supplier->name));

        return [
            'missing_columns' => [],
            'rows' => $rows->map(function (array $row) use ($skus, $barcodes, $existingSkus, $existingBarcodes, $suppliers): array {
                $data = $row['data'];
                $errors = [];
                $warnings = [];

                if ($data['sku'] === '') {
                    $errors[] = __('Falta la clave.');
                } elseif (in_array(mb_strtoupper($data['sku']), $existingSkus, true)) {
                    $errors[] = __('La clave :sku ya existe en el sistema.', ['sku' => $data['sku']]);
                } elseif (($twins = $this->otherLines($skus[mb_strtoupper($data['sku'])], $row['line'])) !== []) {
                    $errors[] = __('Clave repetida con la fila :lines.', ['lines' => implode(', ', $twins)]);
                }

                if ($data['name'] === '') {
                    $errors[] = __('Falta el nombre.');
                }

                if ($data['barcode'] !== '') {
                    if (in_array($data['barcode'], $existingBarcodes, true)) {
                        $errors[] = __('El código de barras ya lo tiene otro producto del sistema.');
                    } elseif (($twins = $this->otherLines($barcodes[$data['barcode']], $row['line'])) !== []) {
                        $errors[] = __('Código de barras repetido con la fila :lines.', ['lines' => implode(', ', $twins)]);
                    }
                }

                foreach (['reference_cost' => 'Costo', 'unit_price' => 'Precio', 'wholesale_price' => 'Precio mayoreo', 'wholesale_min_quantity' => 'Mayoreo desde', 'stock' => 'Existencia', 'minimum' => 'Mínimo'] as $field => $label) {
                    if ($data[$field] !== '' && $this->number($data[$field]) === null) {
                        $errors[] = __(':column debe ser un número mayor o igual a cero.', ['column' => $label]);
                    }
                }

                foreach (['tax_rate' => 'IVA %', 'ieps_rate' => 'IEPS %'] as $field => $label) {
                    if ($data[$field] !== '' && $this->rate($data[$field]) === null) {
                        $errors[] = __(':column debe ser un porcentaje, por ejemplo 16 o 0.', ['column' => $label]);
                    }
                }

                $consignment = $this->yes($data['consignment']);

                if ($data['supplier'] !== '' && ! $suppliers->has($this->normalize($data['supplier']))) {
                    $errors[] = __('El proveedor «:supplier» no está dado de alta.', ['supplier' => $data['supplier']]);
                } elseif ($data['supplier'] === '') {
                    if ($consignment) {
                        $errors[] = __('Lo que está en consigna necesita su proveedor.');
                    } else {
                        $warnings[] = __('Sin proveedor: se importa sin proveedor.');
                    }
                }

                if ($data['unit_price'] === '') {
                    $warnings[] = __('Sin precio: se importa como no vendible.');
                }

                if ($data['tax_rate'] === '') {
                    $warnings[] = __('Sin IVA: no se podrá vender hasta capturar su tasa.');
                }

                if ($data['barcode'] === '') {
                    $warnings[] = __('Sin código de barras: se generará uno interno.');
                }

                if ($data['reference_cost'] === '' && ($this->number($data['stock']) ?? 0) > 0) {
                    $warnings[] = __('Sin costo: su utilidad no se podrá calcular.');
                }

                return [...$row, 'errors' => $errors, 'warnings' => $warnings];
            })->all(),
        ];
    }

    /**
     * Create the products of the rows without errors and put their stock in the warehouse.
     *
     * @param  list<list<string>>  $sheet
     * @return array{products: int, with_stock: int, skipped: int}
     */
    public function import(User $user, Warehouse $warehouse, array $sheet, string $fileName): array
    {
        $analysis = $this->analyze($sheet);
        $valid = collect($analysis['rows'])->filter(fn (array $row) => $row['errors'] === []);
        $suppliers = Supplier::query()->get()->keyBy(fn (Supplier $supplier) => $this->normalize($supplier->name));

        return DB::transaction(function () use ($user, $warehouse, $valid, $analysis, $suppliers, $fileName): array {
            $stock = [];

            foreach ($valid as $row) {
                $data = $row['data'];
                $supplier = $data['supplier'] !== '' ? $suppliers[$this->normalize($data['supplier'])] : null;

                $product = Product::create([
                    'sku' => $data['sku'],
                    'name' => $data['name'],
                    'brand' => $data['brand'] ?: null,
                    'presentation' => $data['presentation'] ?: null,
                    'unit' => $data['unit'] ?: 'pieza',
                    'barcode' => $data['barcode'] ?: Product::newInternalBarcode(),
                    'category_id' => $data['category'] !== '' ? Category::query()->firstOrCreate(['name' => $data['category']])->id : null,
                    'supplier_id' => $supplier?->id,
                    'reference_cost' => $this->number($data['reference_cost']),
                    'unit_price' => $this->number($data['unit_price']),
                    'wholesale_price' => $this->number($data['wholesale_price']),
                    'wholesale_min_quantity' => $this->number($data['wholesale_min_quantity']),
                    'tax_rate' => $this->rate($data['tax_rate']),
                    'ieps_rate' => $this->rate($data['ieps_rate']),
                    'product_key' => $data['product_key'] ?: null,
                    'unit_key' => $data['unit_key'] ? mb_strtoupper($data['unit_key']) : null,
                    'is_active' => true,
                ]);

                if (($minimum = $this->number($data['minimum'])) !== null) {
                    StockMinimum::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'min_quantity' => $minimum]);
                }

                $quantity = $this->number($data['stock']);

                if ($quantity !== null && bccomp($quantity, '0', 3) === 1) {
                    $stock[] = [
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $this->number($data['reference_cost']),
                        'supplier_id' => $supplier?->id,
                        'consignment' => $this->yes($data['consignment']),
                    ];
                }
            }

            if ($stock !== []) {
                $this->inventory->openingStock($user, $warehouse, $stock, __('Importación :file', ['file' => Str::limit($fileName, 200)]));
            }

            return ['products' => $valid->count(), 'with_stock' => count($stock), 'skipped' => count($analysis['rows']) - $valid->count()];
        });
    }

    /**
     * @param  Collection<int, array{line: int}>  $rows
     * @return list<int>
     */
    private function otherLines(Collection $rows, int $line): array
    {
        return $rows->pluck('line')->reject(fn (int $other) => $other === $line)->values()->all();
    }

    /**
     * A non-negative amount like "1,250.00" or "$390" as a decimal string, or null.
     */
    private function number(string $value): ?string
    {
        $value = str_replace([',', '$', ' '], '', $value);

        if ($value === '' || ! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    /**
     * A percentage as a fraction: "16", "16%" and "0.16" are all 0.1600.
     */
    private function rate(string $value): ?string
    {
        $value = str_replace(['%', ' '], '', $value);

        if ($value === '' || ! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            return null;
        }

        $rate = (float) $value >= 1 ? (float) $value / 100 : (float) $value;

        return number_format($rate, 4, '.', '');
    }

    private function yes(string $value): bool
    {
        return in_array($this->normalize($value), ['si', 's', 'yes', 'x', '1', 'consigna'], true);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}

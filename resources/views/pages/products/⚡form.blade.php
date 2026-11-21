<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Facturapi\Exceptions\Facturapi_Exception;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Producto')] class extends Component {
    public ?Product $product = null;

    public string $name = '';

    public string $sku = '';

    public string $barcode = '';

    public ?int $supplier_id = null;

    public ?int $category_id = null;

    public string $brand = '';

    public string $presentation = '';

    public string $unit = 'pieza';

    public string $description = '';

    public string $reference_cost = '';

    public string $unit_price = '';

    public string $wholesale_price = '';

    public string $wholesale_min_quantity = '';

    /** No default: the IVA rate is chosen for every product (task 7). */
    public string $tax_rate = '';

    public string $ieps_rate = '';

    public string $product_key = '';

    public string $product_key_name = '';

    public string $product_key_search = '';

    /** @var array<int, array{key: string, description: string}> */
    public array $product_key_results = [];

    public string $unit_key = '';

    public string $unit_name = '';

    public string $unit_key_search = '';

    /** @var array<int, array{key: string, description: string}> */
    public array $unit_key_results = [];

    /**
     * Minimum stock by warehouse id; blank means no minimum.
     *
     * @var array<int, string>
     */
    public array $minimums = [];

    public bool $is_active = true;

    public ?bool $skuAvailable = null;

    public ?bool $barcodeAvailable = null;

    public int $label_copies = 1;

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->product = $product->refresh();
            $this->fillFrom($this->product);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $id = $this->product?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($id)],
            'brand' => ['nullable', 'string', 'max:255'],
            'presentation' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'unit' => ['required', 'string', 'max:50'],
            'reference_cost' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'ieps_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'product_key' => ['nullable', 'string', 'max:8'],
            'product_key_name' => ['nullable', 'string', 'max:255'],
            'unit_key' => ['nullable', 'string', 'max:3'],
            'unit_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'minimums.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'tax_rate' => __('tasa de IVA'),
            'wholesale_min_quantity' => __('mayoreo desde'),
            'minimums.*' => __('existencia mínima'),
        ];
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_sellable')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * What the customer pays, taxes included: IEPS on the price and IVA on both.
     */
    #[Computed]
    public function priceWithTax(): float
    {
        return round((float) ($this->unit_price ?: 0) * (1 + (float) ($this->ieps_rate ?: 0)) * (1 + (float) ($this->tax_rate ?: 0)), 2);
    }

    public function updatedSku(): void
    {
        $this->skuAvailable = $this->checkAvailability('sku', $this->sku);
    }

    public function updatedBarcode(): void
    {
        $this->barcodeAvailable = $this->checkAvailability('barcode', $this->barcode);
    }

    public function generateBarcode(): void
    {
        $this->barcode = Product::newInternalBarcode();
        $this->barcodeAvailable = true;
    }

    public function updatedProductKeySearch(FacturapiGateway $gateway): void
    {
        $this->product_key_results = $this->searchCatalog($gateway, 'searchProductKeys', $this->product_key_search, 3);
    }

    public function selectProductKey(string $key): void
    {
        $match = collect($this->product_key_results)->firstWhere('key', $key);

        $this->product_key = $key;
        $this->product_key_name = $match['description'] ?? '';
        $this->product_key_search = $this->formatCatalogLabel($key, $this->product_key_name);
        $this->product_key_results = [];
    }

    public function clearProductKey(): void
    {
        $this->reset(['product_key', 'product_key_name', 'product_key_search', 'product_key_results']);
    }

    public function updatedUnitKeySearch(FacturapiGateway $gateway): void
    {
        $this->unit_key_results = $this->searchCatalog($gateway, 'searchUnitKeys', $this->unit_key_search, 2);
    }

    public function selectUnitKey(string $key): void
    {
        $match = collect($this->unit_key_results)->firstWhere('key', $key);

        $this->unit_key = $key;
        $this->unit_name = $match['description'] ?? '';
        $this->unit_key_search = $this->formatCatalogLabel($key, $this->unit_name);
        $this->unit_key_results = [];
    }

    public function clearUnitKey(): void
    {
        $this->reset(['unit_key', 'unit_name', 'unit_key_search', 'unit_key_results']);
    }

    public function save(): void
    {
        $product = $this->persist();

        Flux::toast(variant: 'success', text: __('Producto guardado.'));
        $this->redirectRoute('products.edit', $product, navigate: true);
    }

    /**
     * Save the new product and leave the form empty, ready for the next one.
     */
    public function saveAndAddAnother(): void
    {
        $this->persist();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Producto guardado. Listo para el siguiente.'));
    }

    /**
     * Delete a product that was never used. One with lots, sales or invoices is
     * deactivated instead, because inventory history is never deleted.
     */
    public function delete(): void
    {
        $product = $this->product;
        $hasHistory = $product->lots()->exists()
            || $product->invoiceItems()->exists()
            || SaleItem::query()->where('product_id', $product->id)->exists();

        if ($hasHistory) {
            $product->update(['is_active' => false]);
        } else {
            DB::transaction(function () use ($product): void {
                $product->stockMinimums()->delete();
                $product->delete();
            });
        }

        Flux::toast(variant: 'success', text: $hasHistory
            ? __('El producto tiene movimientos: se desactivó en lugar de eliminarse.')
            : __('Producto eliminado.'));

        $this->redirectRoute('inventory.index', navigate: true);
    }

    /**
     * Validate and save the product with its minimum stock by warehouse. Blank
     * optional fields (no price yet, no SAT key…) are stored as null.
     */
    private function persist(): Product
    {
        $validated = $this->validate();
        $minimums = $validated['minimums'] ?? [];
        unset($validated['minimums']);

        return DB::transaction(function () use ($validated, $minimums): Product {
            $attributes = array_map(fn ($value) => $value === '' ? null : $value, $validated);
            $product = $this->product ?? new Product;
            $product->fill($attributes)->save();

            foreach ($this->warehouses as $warehouse) {
                $minimum = $minimums[$warehouse->id] ?? null;

                blank($minimum)
                    ? $product->stockMinimums()->where('warehouse_id', $warehouse->id)->delete()
                    : $product->stockMinimums()->updateOrCreate(['warehouse_id' => $warehouse->id], ['min_quantity' => $minimum]);
            }

            return $product;
        });
    }

    private function fillFrom(Product $product): void
    {
        $this->name = $product->name;
        $this->description = (string) $product->description;
        $this->sku = $product->sku;
        $this->barcode = (string) $product->barcode;
        $this->brand = (string) $product->brand;
        $this->presentation = (string) $product->presentation;
        $this->category_id = $product->category_id;
        $this->supplier_id = $product->supplier_id;
        $this->unit = $product->unit;
        $this->reference_cost = (string) $product->reference_cost;
        $this->unit_price = (string) $product->unit_price;
        $this->wholesale_price = (string) $product->wholesale_price;
        $this->wholesale_min_quantity = $product->wholesale_min_quantity === null ? '' : rtrim(rtrim($product->wholesale_min_quantity, '0'), '.');
        $this->tax_rate = (string) $product->tax_rate;
        $this->ieps_rate = (string) $product->ieps_rate;
        $this->is_active = $product->is_active;
        $this->minimums = $product->stockMinimums()->pluck('min_quantity', 'warehouse_id')
            ->map(fn ($quantity) => rtrim(rtrim((string) $quantity, '0'), '.'))
            ->all();
        $this->product_key = (string) $product->product_key;
        $this->product_key_name = (string) $product->product_key_name;
        $this->product_key_search = $product->product_key ? $this->formatCatalogLabel($product->product_key, (string) $product->product_key_name) : '';
        $this->unit_key = (string) $product->unit_key;
        $this->unit_name = (string) $product->unit_name;
        $this->unit_key_search = $product->unit_key ? $this->formatCatalogLabel($product->unit_key, (string) $product->unit_name) : '';
    }

    private function resetForm(): void
    {
        $this->reset([
            'product', 'name', 'description', 'sku', 'barcode', 'unit_price', 'tax_rate',
            'brand', 'presentation', 'category_id', 'supplier_id', 'unit', 'reference_cost',
            'wholesale_price', 'wholesale_min_quantity', 'ieps_rate', 'minimums', 'is_active',
            'product_key', 'product_key_name', 'product_key_search', 'product_key_results',
            'unit_key', 'unit_name', 'unit_key_search', 'unit_key_results',
            'skuAvailable', 'barcodeAvailable',
        ]);
        $this->resetValidation();
    }

    private function checkAvailability(string $column, string $value): ?bool
    {
        if (trim($value) === '') {
            return null;
        }

        return ! Product::query()->where($column, $value)
            ->when($this->product, fn ($query) => $query->whereKeyNot($this->product->id))
            ->exists();
    }

    /**
     * @return array<int, array{key: string, description: string}>
     */
    private function searchCatalog(FacturapiGateway $gateway, string $method, string $query, int $minLength): array
    {
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        try {
            return $gateway->{$method}($query);
        } catch (Facturapi_Exception) {
            Flux::toast(variant: 'danger', text: __('No se pudo buscar en el catálogo del SAT en este momento.'));

            return [];
        }
    }

    private function formatCatalogLabel(string $key, string $description): string
    {
        return $description !== '' ? "{$key} — {$description}" : $key;
    }
}; ?>

@php
    $ean13 = app(\App\Services\Ean13::class);
    $printableCode = $ean13->normalize($barcode);
@endphp

<form wire:submit="save" class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('inventory.index')" wire:navigate>{{ __('Inventario') }}</flux:button>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <flux:heading size="xl">{{ $product ? $product->name : __('Nuevo producto') }}</flux:heading>
                @if ($product && ! $product->is_active)
                    <flux:badge size="sm" color="zinc">{{ __('Inactivo') }}</flux:badge>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">{{ __('Cancelar') }}</flux:button>
                @unless ($product)
                    <flux:button type="button" wire:click="saveAndAddAnother" data-test="save-and-add-another-button">{{ __('Guardar y agregar otro') }}</flux:button>
                @endunless
                <flux:button type="submit" variant="primary" data-test="save-product-button">{{ __('Guardar producto') }}</flux:button>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('Identificación') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Nombre')" />
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Clave interna') }}</flux:label>
                        <flux:input wire:model.live.debounce.400ms="sku" />
                        @if ($skuAvailable === false)
                            <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ __('Esa clave ya la tiene otro producto.') }}</flux:text>
                        @elseif ($skuAvailable === true)
                            <flux:text size="sm" class="text-green-700 dark:text-green-400">{{ __('Clave disponible.') }}</flux:text>
                        @endif
                        <flux:error name="sku" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Código de barras') }}</flux:label>
                        <flux:input.group>
                            <flux:input wire:model.live.debounce.400ms="barcode" />
                            <flux:button type="button" wire:click="generateBarcode" data-test="generate-barcode-button">{{ __('Generar') }}</flux:button>
                        </flux:input.group>
                        @if ($barcodeAvailable === false)
                            <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ __('Ese código ya lo tiene otro producto.') }}</flux:text>
                        @else
                            <flux:description>{{ __('Escanéalo, escríbelo o genera uno interno') }}</flux:description>
                        @endif
                        <flux:error name="barcode" />
                    </flux:field>
                </div>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <flux:select wire:model="supplier_id" :label="__('Proveedor principal')" :placeholder="__('Elegir…')">
                        <flux:select.option value="">{{ __('Sin proveedor') }}</flux:select.option>
                        @foreach ($this->suppliers as $supplier)
                            <flux:select.option :value="$supplier->id">{{ $supplier->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="category_id" :label="__('Categoría')" :placeholder="__('Elegir…')">
                        <flux:select.option value="">{{ __('Sin categoría') }}</flux:select.option>
                        @foreach ($this->categories as $category)
                            <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="grid items-start gap-4 sm:grid-cols-3">
                    <flux:input wire:model="brand" :label="__('Marca')" />
                    <flux:input wire:model="presentation" :label="__('Presentación')" :placeholder="__('Ej. 20 L')" />
                    <flux:input wire:model="unit" :label="__('Unidad de venta')" :placeholder="__('Ej. garrafa')" />
                </div>
                <flux:textarea wire:model="description" :label="__('Descripción')" rows="2" />
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('Precios') }}</flux:heading>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <flux:input wire:model="reference_cost" type="number" step="0.01" min="0" :label="__('Costo de referencia')" />
                    <flux:input wire:model.live.debounce.400ms="unit_price" type="number" step="0.01" min="0" :label="__('Precio público (opcional)')" />
                    <flux:input wire:model="wholesale_price" type="number" step="0.01" min="0" :label="__('Precio mayoreo')" />
                    <flux:input wire:model="wholesale_min_quantity" type="number" step="any" min="0" :label="__('Mayoreo desde')" :placeholder="__('piezas')" />
                </div>
                <flux:text size="sm">{{ __('El costo real se guarda en cada entrada; este se usa cuando la mercancía no trae costo propio. Sin precio público el producto sí existe en inventario y en los traspasos, pero el punto de venta no deja venderlo.') }}</flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('Datos para facturar') }}</flux:heading>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="relative">
                        <flux:field>
                            <flux:label>{{ __('Clave de producto SAT') }}</flux:label>
                            <flux:input.group>
                                <flux:input wire:model.live.debounce.400ms="product_key_search" :placeholder="__('Busca: fertilizante…')" autocomplete="off" />
                                @if ($product_key)
                                    <flux:button type="button" icon="x-mark" wire:click="clearProductKey" :aria-label="__('Quitar clave')" />
                                @endif
                            </flux:input.group>
                            <flux:error name="product_key" />
                        </flux:field>
                        @if ($product_key_results)
                            <div class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-line bg-white shadow dark:border-zinc-700 dark:bg-zinc-800">
                                @foreach ($product_key_results as $result)
                                    <button type="button" wire:click="selectProductKey('{{ $result['key'] }}')" class="block w-full px-3 py-2 text-start text-sm hover:bg-surface dark:hover:bg-zinc-700">{{ $result['key'] }} — {{ $result['description'] }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="relative">
                        <flux:field>
                            <flux:label>{{ __('Clave de unidad SAT') }}</flux:label>
                            <flux:input.group>
                                <flux:input wire:model.live.debounce.400ms="unit_key_search" :placeholder="__('Busca: pieza, litro…')" autocomplete="off" />
                                @if ($unit_key)
                                    <flux:button type="button" icon="x-mark" wire:click="clearUnitKey" :aria-label="__('Quitar unidad')" />
                                @endif
                            </flux:input.group>
                            <flux:error name="unit_key" />
                        </flux:field>
                        @if ($unit_key_results)
                            <div class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-line bg-white shadow dark:border-zinc-700 dark:bg-zinc-800">
                                @foreach ($unit_key_results as $result)
                                    <button type="button" wire:click="selectUnitKey('{{ $result['key'] }}')" class="block w-full px-3 py-2 text-start text-sm hover:bg-surface dark:hover:bg-zinc-700">{{ $result['key'] }} — {{ $result['description'] }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <flux:select wire:model.live="tax_rate" :label="__('IVA')" :placeholder="__('Elegir tasa…')">
                        <flux:select.option value="0.1600">{{ __('16 %') }}</flux:select.option>
                        <flux:select.option value="0.0800">{{ __('8 % (frontera)') }}</flux:select.option>
                        <flux:select.option value="0.0000">{{ __('0 %') }}</flux:select.option>
                    </flux:select>
                    <flux:select wire:model.live="ieps_rate" :label="__('IEPS')">
                        <flux:select.option value="">{{ __('No aplica') }}</flux:select.option>
                        <flux:select.option value="0.0800">{{ __('8 %') }}</flux:select.option>
                        <flux:select.option value="0.0600">{{ __('6 %') }}</flux:select.option>
                        <flux:select.option value="0.0300">{{ __('3 %') }}</flux:select.option>
                    </flux:select>
                </div>
                <flux:text size="sm">{{ __('Se capturan una vez; la venta y la factura calculan los impuestos solas.') }}</flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('Existencia mínima por almacén') }}</flux:heading>
                <div class="grid items-start gap-4 sm:grid-cols-3">
                    @foreach ($this->warehouses as $warehouse)
                        <flux:input wire:model="minimums.{{ $warehouse->id }}" type="number" step="any" min="0" :label="$warehouse->name" wire:key="minimum-{{ $warehouse->id }}" />
                    @endforeach
                </div>
            </flux:card>
        </div>

        <div class="flex flex-col gap-6">
            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Etiqueta') }}</flux:heading>
                <div class="rounded-lg border border-dashed border-line p-3 dark:border-zinc-600">
                    <div class="flex justify-between gap-2 text-sm">
                        <span class="font-semibold">{{ $name ?: __('Nombre del producto') }}</span>
                        @if ($unit_price !== '')
                            <span class="font-bold tabular-nums">${{ number_format($this->priceWithTax, 2) }}</span>
                        @endif
                    </div>
                    <div class="mt-2 rounded bg-white p-1">
                        @if ($printableCode)
                            <div class="h-10 overflow-hidden">{!! $ean13->svg($printableCode) !!}</div>
                            <div class="text-center font-mono text-xs tracking-wider text-zinc-800">{{ $ean13->digits($printableCode) }}</div>
                        @else
                            <flux:text size="sm" class="text-center">{{ __('Genera o escribe un código EAN-13') }}</flux:text>
                        @endif
                    </div>
                </div>
                @if ($product)
                    <div class="flex items-end gap-2">
                        <div class="w-24"><flux:input wire:model.live="label_copies" type="number" min="1" max="500" :label="__('Cantidad')" /></div>
                        <flux:button icon="printer" :href="route('products.labels', ['p' => [$product->id => max(1, (int) $label_copies)]])" target="_blank">{{ __('Imprimir etiquetas') }}</flux:button>
                    </div>
                @else
                    <flux:text size="sm">{{ __('Guarda el producto para imprimir sus etiquetas.') }}</flux:text>
                @endif
            </flux:card>

            <flux:card class="flex flex-col gap-2">
                <flux:heading>{{ __('¿Y la existencia?') }}</flux:heading>
                <flux:text size="sm">{{ __('La existencia no se escribe aquí. Se registra con una entrada de mercancía, así queda el proveedor, el costo y el lote.') }}</flux:text>
                <flux:link :href="route('inventory.entry')" wire:navigate class="text-sm">{{ __('Registrar entrada →') }}</flux:link>
            </flux:card>

            @if ($product)
                <flux:card class="flex flex-col gap-3">
                    <flux:switch wire:model="is_active" :label="__('Activo')" :description="__('Un producto inactivo no aparece en el punto de venta ni en el inventario.')" />
                    <flux:link :href="route('inventory.kardex', $product)" wire:navigate class="text-sm">{{ __('Ver kardex') }}</flux:link>
                    <flux:modal.trigger name="product-delete">
                        <flux:button type="button" variant="ghost" size="sm" icon="trash">{{ __('Eliminar producto') }}</flux:button>
                    </flux:modal.trigger>
                </flux:card>
            @endif
        </div>
    </div>

    @if ($product)
        <flux:modal name="product-delete" class="md:w-96">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('¿Eliminar producto?') }}</flux:heading>
                <flux:text>{{ __('Si ya tiene movimientos no se borra: se desactiva, para no perder su historial.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Regresar') }}</flux:button></flux:modal.close>
                    <flux:button type="button" variant="danger" wire:click="delete" data-test="delete-product-button">{{ __('Eliminar') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</form>

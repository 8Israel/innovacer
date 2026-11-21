<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Facturapi\Exceptions\Facturapi_Exception;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Productos')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $sku = '';

    public string $barcode = '';

    public ?string $unit_price = '';

    /** No default: the IVA rate is chosen for every product (task 7). */
    public string $tax_rate = '';

    public string $brand = '';

    public string $presentation = '';

    public ?int $category_id = null;

    public ?int $supplier_id = null;

    public string $unit = 'pieza';

    public string $reference_cost = '';

    public string $wholesale_price = '';

    public string $wholesale_min_quantity = '';

    public string $ieps_rate = '';

    /**
     * Minimum stock by warehouse id; blank means no minimum.
     *
     * @var array<int, string>
     */
    public array $minimums = [];

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

    public ?int $deletingId = null;

    public string $search = '';

    public string $barcodeScan = '';

    public ?bool $skuAvailable = null;

    public ?bool $barcodeAvailable = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sku' => ['required', 'string', 'max:255', 'unique:products,sku,'.$this->editingId],
            'barcode' => ['nullable', 'string', 'max:255', 'unique:products,barcode,'.$this->editingId],
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

    public function generateBarcode(): void
    {
        $this->barcode = Product::newInternalBarcode();
        $this->barcodeAvailable = true;
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('product-form')->show();
    }

    public function edit(int $productId): void
    {
        $product = Product::findOrFail($productId);

        $this->editingId = $product->id;
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
        $this->minimums = $product->stockMinimums()->pluck('min_quantity', 'warehouse_id')
            ->map(fn ($quantity) => rtrim(rtrim((string) $quantity, '0'), '.'))
            ->all();
        $this->product_key = (string) $product->product_key;
        $this->product_key_name = (string) $product->product_key_name;
        $this->product_key_search = $product->product_key ? $this->formatCatalogLabel($product->product_key, (string) $product->product_key_name) : '';
        $this->unit_key = (string) $product->unit_key;
        $this->unit_name = (string) $product->unit_name;
        $this->unit_key_search = $product->unit_key ? $this->formatCatalogLabel($product->unit_key, (string) $product->unit_name) : '';

        Flux::modal('product-form')->show();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSku(): void
    {
        $this->skuAvailable = $this->checkAvailability('sku', $this->sku);
    }

    public function updatedBarcode(): void
    {
        $this->barcodeAvailable = $this->checkAvailability('barcode', $this->barcode);
    }

    private function checkAvailability(string $column, string $value): ?bool
    {
        if (trim($value) === '') {
            return null;
        }

        return ! Product::where($column, $value)
            ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
            ->exists();
    }

    /**
     * Live preview of the price the customer actually pays, tax included.
     */
    #[Computed]
    public function priceWithTax(): float
    {
        return round((float) ($this->unit_price ?: 0) * (1 + (float) ($this->tax_rate ?: 0)), 2);
    }

    public function clearProductKey(): void
    {
        $this->reset(['product_key', 'product_key_name', 'product_key_search', 'product_key_results']);
    }

    public function clearUnitKey(): void
    {
        $this->reset(['unit_key', 'unit_name', 'unit_key_search', 'unit_key_results']);
    }

    public function scanBarcode(): void
    {
        $code = trim($this->barcodeScan);
        $this->barcodeScan = '';

        if ($code === '') {
            return;
        }

        $product = Product::where('barcode', $code)->first();

        if (! $product) {
            Flux::toast(variant: 'danger', text: __('No se encontró ningún producto con ese código de barras.'));

            return;
        }

        $this->edit($product->id);
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

    /**
     * Validate and save the product with its minimum stock by warehouse. Blank
     * optional fields (no price yet, no SAT key…) are stored as null.
     */
    private function persist(): Product
    {
        $validated = $this->validate();
        $minimums = $validated['minimums'] ?? [];
        unset($validated['minimums']);

        $product = DB::transaction(function () use ($validated, $minimums): Product {
            $product = Product::updateOrCreate(
                ['id' => $this->editingId],
                array_map(fn ($value) => $value === '' ? null : $value, $validated),
            );

            foreach ($this->warehouses as $warehouse) {
                $minimum = $minimums[$warehouse->id] ?? null;

                blank($minimum)
                    ? $product->stockMinimums()->where('warehouse_id', $warehouse->id)->delete()
                    : $product->stockMinimums()->updateOrCreate(['warehouse_id' => $warehouse->id], ['min_quantity' => $minimum]);
            }

            return $product;
        });

        unset($this->products);

        return $product;
    }

    public function save(): void
    {
        $this->persist();

        Flux::modal('product-form')->close();

        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Producto guardado.'));
    }

    /**
     * Save the product but keep the modal open, ready for the next one.
     * Only available while creating (not editing an existing product).
     */
    public function saveAndAddAnother(): void
    {
        $this->editingId = null;
        $this->persist();

        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Producto guardado. Listo para el siguiente.'));
    }

    public function confirmDelete(int $productId): void
    {
        $this->deletingId = $productId;

        Flux::modal('product-delete')->show();
    }

    /**
     * Delete a product that was never used. One with lots, sales or invoices is
     * deactivated instead, because inventory history is never deleted.
     */
    public function delete(): void
    {
        $product = Product::query()->findOrFail($this->deletingId);

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

        $this->deletingId = null;

        Flux::modal('product-delete')->close();

        Flux::toast(variant: 'success', text: $hasHistory
            ? __('El producto tiene movimientos: se desactivó en lugar de eliminarse.')
            : __('Producto eliminado.'));
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'description', 'sku', 'barcode', 'unit_price', 'tax_rate',
            'brand', 'presentation', 'category_id', 'supplier_id', 'unit', 'reference_cost',
            'wholesale_price', 'wholesale_min_quantity', 'ieps_rate', 'minimums',
            'product_key', 'product_key_name', 'product_key_search', 'product_key_results',
            'unit_key', 'unit_name', 'unit_key_search', 'unit_key_results',
            'skuAvailable', 'barcodeAvailable',
        ]);
        $this->resetValidation();
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    #[Computed]
    public function products()
    {
        return Product::query()
            ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")
                ->orWhere('barcode', 'like', "%{$this->search}%")
            ))
            ->orderBy('name')
            ->paginate(10);
    }
}; ?>

<div class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Productos') }}</flux:heading>
            <flux:subheading>{{ __('Catálogo de productos para facturación e inventario.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">
            {{ __('Nuevo producto') }}
        </flux:button>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :label="__('Buscar')"
            placeholder="{{ __('Nombre, SKU o código de barras...') }}"
        />

        <form wire:submit.prevent="scanBarcode">
            <flux:input
                wire:model="barcodeScan"
                icon="qr-code"
                :label="__('Escanear código de barras')"
                placeholder="{{ __('Apunta el escáner aquí y dispara') }}"
                autocomplete="off"
            />
        </form>
    </div>

    <flux:table :paginate="$this->products">
        <flux:table.columns>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('SKU') }}</flux:table.column>
            <flux:table.column>{{ __('Código de barras') }}</flux:table.column>
            <flux:table.column>{{ __('Precio unitario') }}</flux:table.column>
            <flux:table.column>{{ __('IVA') }}</flux:table.column>
            <flux:table.column>{{ __('Unidad') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->products as $product)
                <flux:table.row :key="$product->id">
                    <flux:table.cell>{{ $product->name }}</flux:table.cell>
                    <flux:table.cell>{{ $product->sku }}</flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ $product->barcode ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($product->unit_price !== null)
                            ${{ number_format((float) $product->unit_price, 2) }}
                        @else
                            <flux:badge size="sm" color="amber">{{ __('Sin precio') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ number_format((float) $product->tax_rate * 100, 0) }}%</flux:table.cell>
                    <flux:table.cell>{{ $product->unit_name }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" icon="pencil" wire:click="edit({{ $product->id }})">
                                {{ __('Editar') }}
                            </flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $product->id }})">
                                {{ __('Eliminar') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">
                        {{ __('Aún no hay productos registrados.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="product-form" class="md:w-2xl" scroll="body" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Editar producto') : __('Nuevo producto') }}
            </flux:heading>

            <flux:input wire:model="name" :label="__('Nombre')" required autofocus />

            <div class="grid grid-cols-2 items-start gap-4 md:grid-cols-3">
                <flux:input wire:model="brand" :label="__('Marca')" />
                <flux:input wire:model="presentation" :label="__('Presentación')" :placeholder="__('Ej. 20 L')" />
                <flux:input wire:model="unit" :label="__('Unidad de venta')" :placeholder="__('Ej. garrafa, pieza, metro')" required />
                <flux:select wire:model="supplier_id" :label="__('Proveedor principal')" :placeholder="__('Elige…')">
                    <flux:select.option value="">{{ __('Sin proveedor') }}</flux:select.option>
                    @foreach ($this->suppliers as $supplier)
                        <flux:select.option :value="$supplier->id">{{ $supplier->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="category_id" :label="__('Categoría')" :placeholder="__('Elige…')">
                    <flux:select.option value="">{{ __('Sin categoría') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-2 items-start gap-4">
                <div class="relative">
                    <div class="flex items-end gap-1">
                        <div class="flex-1">
                            <flux:input
                                wire:model.live.debounce.400ms="product_key_search"
                                :label="__('Clave SAT de producto/servicio')"
                                placeholder="{{ __('Haz clic para ver opciones, o escribe para buscar...') }}"
                                onfocus="this.select()"
                                autocomplete="off"
                            />
                        </div>
                        @if ($product_key)
                            <flux:button size="sm" variant="ghost" icon="x-mark" type="button" wire:click="clearProductKey" />
                        @endif
                    </div>

                    <div wire:loading wire:target="product_key_search" class="mt-1 text-xs text-zinc-500">
                        {{ __('Buscando en el catálogo del SAT...') }}
                    </div>

                    @if (count($product_key_results) > 0)
                        <div class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            @foreach ($product_key_results as $result)
                                <button
                                    type="button"
                                    wire:click="selectProductKey('{{ $result['key'] }}')"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                >
                                    <span class="font-mono text-xs text-zinc-500">{{ $result['key'] }}</span>
                                    — {{ $result['description'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @error('product_key')
                        <flux:text class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                </div>

                <div class="relative">
                    <div class="flex items-end gap-1">
                        <div class="flex-1">
                            <flux:input
                                wire:model.live.debounce.400ms="unit_key_search"
                                :label="__('Clave SAT de unidad')"
                                placeholder="{{ __('Haz clic para ver opciones, o escribe para buscar...') }}"
                                onfocus="this.select()"
                                autocomplete="off"
                            />
                        </div>
                        @if ($unit_key)
                            <flux:button size="sm" variant="ghost" icon="x-mark" type="button" wire:click="clearUnitKey" />
                        @endif
                    </div>

                    <div wire:loading wire:target="unit_key_search" class="mt-1 text-xs text-zinc-500">
                        {{ __('Buscando en el catálogo del SAT...') }}
                    </div>

                    @if (count($unit_key_results) > 0)
                        <div class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            @foreach ($unit_key_results as $result)
                                <button
                                    type="button"
                                    wire:click="selectUnitKey('{{ $result['key'] }}')"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                >
                                    <span class="font-mono text-xs text-zinc-500">{{ $result['key'] }}</span>
                                    — {{ $result['description'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @error('unit_key')
                        <flux:text class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 items-start gap-4">
                <div>
                    <flux:input wire:model.live.debounce.500ms="sku" :label="__('SKU')" required />
                    <div wire:loading wire:target="sku" class="mt-1 text-xs text-zinc-500">{{ __('Comprobando...') }}</div>
                    @if (! $errors->has('sku'))
                        <div wire:loading.remove wire:target="sku">
                            @if ($skuAvailable === true)
                                <flux:text class="mt-1 text-xs text-green-600 dark:text-green-400">✓ {{ __('Disponible') }}</flux:text>
                            @elseif ($skuAvailable === false)
                                <flux:text class="mt-1 text-xs text-red-600 dark:text-red-400">✗ {{ __('Ya existe un producto con este SKU') }}</flux:text>
                            @endif
                        </div>
                    @endif
                </div>

                <div>
                    <flux:input wire:model.live.debounce.500ms="barcode" icon="qr-code" :label="__('Código de barras')" description:trailing="{{ __('Escanéalo, escríbelo o genera uno interno') }}" autocomplete="off">
                        <x-slot name="iconTrailing">
                            <flux:button size="sm" variant="subtle" type="button" wire:click="generateBarcode" class="-me-1">{{ __('Generar') }}</flux:button>
                        </x-slot>
                    </flux:input>
                    <div wire:loading wire:target="barcode" class="mt-1 text-xs text-zinc-500">{{ __('Comprobando...') }}</div>
                    @if (! $errors->has('barcode'))
                        <div wire:loading.remove wire:target="barcode">
                            @if ($barcodeAvailable === true)
                                <flux:text class="mt-1 text-xs text-green-600 dark:text-green-400">✓ {{ __('Disponible') }}</flux:text>
                            @elseif ($barcodeAvailable === false)
                                <flux:text class="mt-1 text-xs text-red-600 dark:text-red-400">✗ {{ __('Ya existe un producto con este código') }}</flux:text>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 items-start gap-4">
                <flux:input
                    wire:model="reference_cost"
                    :label="__('Costo de referencia')"
                    type="number"
                    step="0.01"
                    min="0"
                    description:trailing="{{ __('El costo real se guarda en cada entrada.') }}"
                />
                <flux:input
                    wire:model.live.debounce.300ms="unit_price"
                    :label="__('Precio público (sin IVA)')"
                    type="number"
                    step="0.01"
                    min="0"
                    description:trailing="{{ __('Opcional: sin precio el producto existe en inventario, pero no se vende.') }}"
                />
                <flux:input wire:model="wholesale_price" :label="__('Precio mayoreo')" type="number" step="0.01" min="0" />
                <flux:input wire:model="wholesale_min_quantity" :label="__('Mayoreo desde')" type="number" step="any" min="0" :placeholder="__('Piezas')" />
            </div>

            <div class="grid grid-cols-2 items-start gap-4">
                <div>
                    <flux:input wire:model.live.debounce.300ms="tax_rate" :label="__('Tasa de IVA')" type="number" step="0.0001" min="0" max="1" :placeholder="__('Elige la tasa')" required />
                    <div class="mt-2 flex gap-2">
                        <flux:button size="sm" type="button" wire:click="$set('tax_rate', '0.16')" :variant="$tax_rate === '0.16' ? 'primary' : 'ghost'">16%</flux:button>
                        <flux:button size="sm" type="button" wire:click="$set('tax_rate', '0.08')" :variant="$tax_rate === '0.08' ? 'primary' : 'ghost'">8% {{ __('Frontera') }}</flux:button>
                        <flux:button size="sm" type="button" wire:click="$set('tax_rate', '0')" :variant="$tax_rate === '0' ? 'primary' : 'ghost'">0%</flux:button>
                    </div>
                </div>

                <flux:input wire:model="ieps_rate" :label="__('Tasa de IEPS')" type="number" step="0.0001" min="0" max="1" :placeholder="__('No aplica')" />
            </div>

            @if ($unit_price !== '' && $unit_price !== null)
                <flux:text class="-mt-2 text-sm text-zinc-500">
                    {{ __('Precio con IVA incluido:') }} <span class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->priceWithTax, 2) }}</span>
                </flux:text>
            @endif

            <flux:textarea wire:model="description" :label="__('Descripción')" />

            <div>
                <flux:heading size="sm">{{ __('Existencia mínima por almacén') }}</flux:heading>
                <div class="mt-2 grid grid-cols-2 gap-4 md:grid-cols-3">
                    @foreach ($this->warehouses as $warehouse)
                        <flux:input wire:key="minimum-{{ $warehouse->id }}" wire:model="minimums.{{ $warehouse->id }}" :label="$warehouse->name" type="number" step="any" min="0" :placeholder="__('Sin mínimo')" />
                    @endforeach
                </div>
            </div>

            <flux:callout icon="information-circle" :heading="__('¿Y la existencia?')">
                <flux:callout.text>{{ __('La existencia no se escribe aquí. Se registra con una entrada de mercancía, así queda el proveedor, el costo y el lote.') }}</flux:callout.text>
            </flux:callout>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                @unless ($editingId)
                    <flux:button type="button" wire:click="saveAndAddAnother">{{ __('Guardar y agregar otro') }}</flux:button>
                @endunless
                <flux:button type="submit" variant="primary">{{ __('Guardar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="product-delete" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('¿Eliminar producto?') }}</flux:heading>
            <flux:text>{{ __('Esta acción no se puede deshacer.') }}</flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Eliminar') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

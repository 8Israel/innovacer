<?php

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Product;
use Facturapi\Exceptions\Facturapi_Exception;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public string $tax_rate = '0.16';

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
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'product_key' => ['required', 'string', 'max:8'],
            'product_key_name' => ['nullable', 'string', 'max:255'],
            'unit_key' => ['required', 'string', 'max:3'],
            'unit_name' => ['required', 'string', 'max:255'],
        ];
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
        $this->unit_price = (string) $product->unit_price;
        $this->tax_rate = (string) $product->tax_rate;
        $this->product_key = $product->product_key;
        $this->product_key_name = (string) $product->product_key_name;
        $this->product_key_search = $this->formatCatalogLabel($product->product_key, (string) $product->product_key_name);
        $this->unit_key = $product->unit_key;
        $this->unit_name = $product->unit_name;
        $this->unit_key_search = $this->formatCatalogLabel($product->unit_key, $product->unit_name);

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
     * An empty price input arrives as '' (not null), but the field is
     * optional — treat a blank value as "no price yet" before validating.
     */
    private function normalizeUnitPrice(): void
    {
        $this->unit_price = $this->unit_price !== '' ? $this->unit_price : null;
    }

    public function save(): void
    {
        $this->normalizeUnitPrice();

        $validated = $this->validate();

        Product::updateOrCreate(['id' => $this->editingId], $validated);

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
        $this->normalizeUnitPrice();

        $validated = $this->validate();

        Product::create($validated);

        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Producto guardado. Listo para el siguiente.'));
    }

    public function confirmDelete(int $productId): void
    {
        $this->deletingId = $productId;

        Flux::modal('product-delete')->show();
    }

    public function delete(): void
    {
        Product::whereKey($this->deletingId)->delete();

        $this->deletingId = null;

        Flux::modal('product-delete')->close();

        Flux::toast(variant: 'success', text: __('Producto eliminado.'));
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'description', 'sku', 'barcode', 'unit_price',
            'product_key', 'product_key_name', 'product_key_search', 'product_key_results',
            'unit_key', 'unit_name', 'unit_key_search', 'unit_key_results',
            'skuAvailable', 'barcodeAvailable',
        ]);
        $this->tax_rate = '0.16';
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
                    <flux:input wire:model.live.debounce.500ms="barcode" icon="qr-code" :label="__('Código de barras')" description:trailing="{{ __('Escanéalo aquí o captúralo manualmente') }}" autocomplete="off" />
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
                    wire:model.live.debounce.300ms="unit_price"
                    :label="__('Precio unitario (sin IVA)')"
                    type="number"
                    step="0.01"
                    min="0"
                    description:trailing="{{ __('Déjalo vacío si aún no tienes el precio.') }}"
                />

                <div>
                    <flux:input wire:model.live.debounce.300ms="tax_rate" :label="__('Tasa de IVA')" type="number" step="0.0001" min="0" max="1" required />
                    <div class="mt-2 flex gap-2">
                        <flux:button size="sm" type="button" wire:click="$set('tax_rate', '0.16')" :variant="$tax_rate === '0.16' ? 'primary' : 'ghost'">16%</flux:button>
                        <flux:button size="sm" type="button" wire:click="$set('tax_rate', '0.08')" :variant="$tax_rate === '0.08' ? 'primary' : 'ghost'">8% {{ __('Frontera') }}</flux:button>
                        <flux:button size="sm" type="button" wire:click="$set('tax_rate', '0')" :variant="$tax_rate === '0' ? 'primary' : 'ghost'">0%</flux:button>
                    </div>
                </div>
            </div>

            @if ($unit_price !== '' && $unit_price !== null)
                <flux:text class="-mt-2 text-sm text-zinc-500">
                    {{ __('Precio con IVA incluido:') }} <span class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->priceWithTax, 2) }}</span>
                </flux:text>
            @endif

            <flux:textarea wire:model="description" :label="__('Descripción')" />

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

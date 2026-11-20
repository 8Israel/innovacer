<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Inventario')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?int $warehouse_id = null;

    #[Url(except: null)]
    public ?int $supplier_id = null;

    #[Url(except: null)]
    public ?int $category_id = null;

    /** Show only products below their minimum or out of stock in the selling warehouse. */
    #[Url(except: false)]
    public bool $only_alerts = false;

    /**
     * Any filter change goes back to the first page.
     */
    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Active warehouses, the selling one first.
     */
    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_sellable')->orderBy('id')->get();
    }

    #[Computed]
    public function sellingWarehouse(): ?Warehouse
    {
        return $this->warehouses->firstWhere('is_sellable', true);
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Products with a price that are below their minimum or out of stock in the
     * selling warehouse.
     *
     * @return array{below_minimum: list<int>, out_of_stock: list<int>}
     */
    #[Computed]
    public function alerts(): array
    {
        if ($this->sellingWarehouse === null) {
            return ['below_minimum' => [], 'out_of_stock' => []];
        }

        $productIds = Product::query()->where('is_active', true)->whereNotNull('unit_price')->pluck('id');
        $stock = $this->stockIn($this->sellingWarehouse, $productIds->all());
        $minimums = StockMinimum::query()->where('warehouse_id', $this->sellingWarehouse->id)->pluck('min_quantity', 'product_id');

        $outOfStock = $productIds->filter(fn (int $id) => (float) ($stock[$id] ?? 0) <= 0);
        $belowMinimum = $productIds->diff($outOfStock)->filter(
            fn (int $id) => $minimums->has($id) && (float) ($stock[$id] ?? 0) <= (float) $minimums[$id],
        );

        return ['below_minimum' => $belowMinimum->values()->all(), 'out_of_stock' => $outOfStock->values()->all()];
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Product::query()
            ->with('supplier')
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term)))
            ->when($this->supplier_id, fn (Builder $query) => $query->where('supplier_id', $this->supplier_id))
            ->when($this->category_id, fn (Builder $query) => $query->where('category_id', $this->category_id))
            ->when($this->warehouse_id, fn (Builder $query) => $query->whereHas(
                'stocks',
                fn (Builder $stocks) => $stocks->where('warehouse_id', $this->warehouse_id)->where('quantity', '>', 0),
            ))
            ->when($this->only_alerts, fn (Builder $query) => $query->whereIn(
                'id',
                [...$this->alerts['below_minimum'], ...$this->alerts['out_of_stock']],
            ))
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Stock of the listed products by product and warehouse.
     *
     * @return array<int, array<int, string>>
     */
    #[Computed]
    public function stockMatrix(): array
    {
        $matrix = [];

        Stock::query()
            ->whereIn('product_id', $this->products->getCollection()->modelKeys())
            ->groupBy('product_id', 'warehouse_id')
            ->selectRaw('product_id, warehouse_id, SUM(quantity) as quantity')
            ->get()
            ->each(function (Stock $row) use (&$matrix): void {
                $matrix[$row->product_id][$row->warehouse_id] = (string) $row->quantity;
            });

        return $matrix;
    }

    /**
     * @return array{label: string, color: string}
     */
    public function statusOf(Product $product): array
    {
        return match (true) {
            $product->unit_price === null => ['label' => __('No se vende'), 'color' => 'zinc'],
            in_array($product->id, $this->alerts['out_of_stock'], true) => ['label' => __('Agotado'), 'color' => 'red'],
            in_array($product->id, $this->alerts['below_minimum'], true) => ['label' => __('Bajo mínimo'), 'color' => 'amber'],
            default => ['label' => __('Disponible'), 'color' => 'green'],
        };
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    private function stockIn(Warehouse $warehouse, array $productIds): array
    {
        return Stock::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as available')
            ->pluck('available', 'product_id')
            ->all();
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Inventario') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Existencias por almacén y dueño del producto') }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="arrows-right-left" :href="route('inventory.transfer')" wire:navigate>{{ __('Traspaso') }}</flux:button>
            <flux:button icon="arrow-uturn-left" :href="route('inventory.loans')" wire:navigate>{{ __('Préstamos') }}</flux:button>
            <flux:button icon="plus" :href="route('products.index')" wire:navigate>{{ __('Nuevo producto') }}</flux:button>
            <flux:button variant="primary" icon="arrow-down-tray" :href="route('inventory.entry')" wire:navigate>{{ __('Registrar entrada') }}</flux:button>
        </div>
    </div>

    @php
        $belowMinimum = count($this->alerts['below_minimum']);
        $outOfStock = count($this->alerts['out_of_stock']);
    @endphp
    @if ($this->sellingWarehouse && ($belowMinimum > 0 || $outOfStock > 0))
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __(':below y :out en :warehouse.', [
                'below' => trans_choice(':count producto bajo el mínimo|:count productos bajo el mínimo', $belowMinimum),
                'out' => trans_choice(':count agotado|:count agotados', $outOfStock),
                'warehouse' => $this->sellingWarehouse->name,
            ]) }}</flux:callout.heading>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="$toggle('only_alerts')">{{ $only_alerts ? __('Ver todos') : __('Ver solo esos') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre, código o código de barras')" />
        <flux:select wire:model.live="warehouse_id" :placeholder="__('Almacén: todos')">
            <flux:select.option value="">{{ __('Almacén: todos') }}</flux:select.option>
            @foreach ($this->warehouses as $warehouse)
                <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="supplier_id" :placeholder="__('Proveedor: todos')">
            <flux:select.option value="">{{ __('Proveedor: todos') }}</flux:select.option>
            @foreach ($this->suppliers as $supplier)
                <flux:select.option :value="$supplier->id">{{ $supplier->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="category_id" :placeholder="__('Categoría: todas')">
            <flux:select.option value="">{{ __('Categoría: todas') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="p-0">
        <flux:table :paginate="$this->products">
            <flux:table.columns>
                <flux:table.column>{{ __('Producto') }}</flux:table.column>
                <flux:table.column>{{ __('Proveedor') }}</flux:table.column>
                @foreach ($this->warehouses as $warehouse)
                    <flux:table.column align="end">{{ $warehouse->name }}</flux:table.column>
                @endforeach
                <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Precio público') }}</flux:table.column>
                <flux:table.column>{{ __('Estado') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->products as $product)
                    @php
                        $byWarehouse = $this->stockMatrix[$product->id] ?? [];
                        $status = $this->statusOf($product);
                    @endphp
                    <flux:table.row :key="$product->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $product->name }}</span>
                            <span class="block text-sm text-zinc-500">{{ $product->sku }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $product->supplier?->name ?? '—' }}</flux:table.cell>
                        @foreach ($this->warehouses as $warehouse)
                            <flux:table.cell align="end" class="tabular-nums">{{ $qty($byWarehouse[$warehouse->id] ?? 0) }}</flux:table.cell>
                        @endforeach
                        <flux:table.cell align="end" class="font-medium tabular-nums">{{ $qty(array_sum(array_map('floatval', $byWarehouse))) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $product->unit_price === null ? __('Sin precio') : $money($product->unit_price) }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell :colspan="5 + $this->warehouses->count()">
                            <flux:text class="py-6 text-center">{{ __('No hay productos con esos filtros.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>

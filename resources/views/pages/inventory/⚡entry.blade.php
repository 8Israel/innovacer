<?php

use App\Concerns\SearchesProducts;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Entrada de mercancía')] class extends Component {
    use SearchesProducts;

    /** A string so the select keeps showing "Elige…" until a supplier is chosen. */
    public string $supplier_id = '';
    public ?int $warehouse_id = null;
    /** 'consignment' (paid as it sells) or 'purchase' (bought, owned by the business). */
    public string $arrival = 'consignment';
    public string $reference = '';
    public string $received_on = '';
    public string $note = '';
    public string $product_search = '';

    /**
     * Lines keyed by product id.
     *
     * @var array<int, array{product_id: int, quantity: string, unit_cost: string}>
     */
    public array $lines = [];

    public function mount(): void
    {
        $this->warehouse_id = Warehouse::query()->where('is_sellable', true)->where('is_active', true)->value('id');
        $this->received_on = today()->toDateString();
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_sellable')->orderBy('id')->get();
    }

    #[Computed]
    public function warehouse(): ?Warehouse
    {
        return $this->warehouses->firstWhere('id', $this->warehouse_id);
    }

    #[Computed]
    public function supplier(): ?Supplier
    {
        return $this->suppliers->firstWhere('id', (int) $this->supplier_id);
    }

    #[Computed]
    public function results(): Collection
    {
        return $this->searchProducts($this->product_search);
    }

    #[Computed]
    public function lineProducts(): Collection
    {
        return Product::query()->findMany(array_keys($this->lines))->keyBy('id');
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function currentStock(): array
    {
        return $this->stockIn($this->warehouse, array_keys($this->lines));
    }

    /**
     * Entering a supplier's merchandise defaults to how that supplier is settled.
     */
    public function updatedSupplierId(): void
    {
        $this->arrival = $this->supplier?->settlement_type === SupplierSettlementType::Purchase ? 'purchase' : 'consignment';
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);

        $this->lines[$product->id] ??= [
            'product_id' => $product->id,
            'quantity' => '1',
            'unit_cost' => (string) $product->reference_cost,
        ];

        $this->reset('product_search');
    }

    public function addFirstResult(): void
    {
        if ($product = $this->results->first()) {
            $this->addProduct($product->id);
        }
    }

    public function removeLine(int $productId): void
    {
        unset($this->lines[$productId]);
    }

    public function receive(InventoryService $inventory): void
    {
        $this->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $inventory->receive(
            Auth::user(),
            $this->supplier,
            $this->warehouse,
            array_values(array_map(fn (array $line) => [...$line, 'unit_cost' => $line['unit_cost'] === '' ? null : $line['unit_cost']], $this->lines)),
            $this->arrival === 'consignment',
            $this->reference ?: null,
            $this->note ?: null,
            Carbon::parse($this->received_on)->isToday() ? now() : Carbon::parse($this->received_on)->endOfDay(),
        );

        $count = count($this->lines);
        $this->reset('lines', 'reference', 'note', 'product_search');
        unset($this->currentStock);

        Flux::toast(variant: 'success', text: trans_choice('Entrada registrada: :count producto.|Entrada registrada: :count productos.', $count));
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $isLoanWarehouse = $this->warehouse?->isLoanWarehouse();
    $total = collect($lines)->reduce(fn ($carry, $line) => $carry + (float) $line['quantity'] * (float) $line['unit_cost'], 0);
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('inventory.index')" wire:navigate>{{ __('Inventario') }}</flux:button>
        <flux:heading size="xl" class="mt-2">{{ __('Entrada de mercancía') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Lo que deja el proveedor se suma solo a la existencia y crea un lote con su costo.') }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="flex flex-col gap-4 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="supplier_id" :label="__('Proveedor')" :placeholder="__('Elige…')">
                    @foreach ($this->suppliers as $supplier)
                        <flux:select.option :value="$supplier->id">{{ $supplier->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="warehouse_id" :label="__('Almacén destino')">
                    @foreach ($this->warehouses as $warehouse)
                        <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="reference" :label="__('Nota del proveedor')" :placeholder="__('Folio de remisión o nota de entrega')" />
                <flux:input wire:model="received_on" :label="__('Fecha')" type="date" :max="today()->toDateString()" />
            </div>

            @unless ($isLoanWarehouse)
                <flux:radio.group wire:model.live="arrival" :label="__('Cómo llega')" variant="cards" class="sm:grid-cols-2">
                    <flux:radio value="consignment" :label="__('En consigna')" :description="__('Se paga lo que se venda')" />
                    <flux:radio value="purchase" :label="__('Comprado')" :description="__('Se paga ya; el producto es propio')" />
                </flux:radio.group>
            @else
                <flux:callout icon="information-circle" :heading="__('Lo que entra a :warehouse es de :lender.', ['warehouse' => $this->warehouse->name, 'lender' => $this->warehouse->lender?->name])" />
            @endunless

            <div class="relative">
                <flux:input wire:model.live.debounce.250ms="product_search" wire:keydown.enter.prevent="addFirstResult" icon="magnifying-glass" :label="__('Agregar producto')" :placeholder="__('Nombre, código o código de barras')" autocomplete="off" />
                @if ($this->results->isNotEmpty())
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                        @foreach ($this->results as $result)
                            <button type="button" wire:key="result-{{ $result->id }}" wire:click="addProduct({{ $result->id }})" class="block w-full px-4 py-2 text-start hover:bg-surface dark:hover:bg-zinc-800">
                                <span class="font-medium">{{ $result->name }}</span>
                                <span class="text-sm text-zinc-500">{{ $result->sku }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($lines !== [])
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Producto') }}</flux:table.column>
                        <flux:table.column>{{ __('Cantidad') }}</flux:table.column>
                        <flux:table.column>{{ __('Costo unitario') }}</flux:table.column>
                        <flux:table.column>{{ __('Existencia') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Importe') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($lines as $productId => $line)
                            @php
                                $product = $this->lineProducts[$productId];
                                $before = (float) ($this->currentStock[$productId] ?? 0);
                            @endphp
                            <flux:table.row :key="'line-'.$productId">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $product->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ $product->sku }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="w-28"><flux:input wire:model.live.debounce.400ms="lines.{{ $productId }}.quantity" type="number" step="any" min="0" size="sm" /></flux:table.cell>
                                <flux:table.cell class="w-36"><flux:input wire:model.live.debounce.400ms="lines.{{ $productId }}.unit_cost" type="number" step="0.01" min="0" size="sm" :placeholder="$isLoanWarehouse ? __('Costo de referencia') : ''" /></flux:table.cell>
                                <flux:table.cell class="tabular-nums">{{ $qty($before) }} → {{ $qty($before + (float) $line['quantity']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $money((float) $line['quantity'] * (float) $line['unit_cost']) }}</flux:table.cell>
                                <flux:table.cell><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeLine({{ $productId }})" :aria-label="__('Quitar')" /></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            <flux:textarea wire:model="note" :label="__('Observaciones')" rows="2" />
        </flux:card>

        <flux:card class="flex flex-col gap-3">
            <flux:heading>{{ __('Qué se va a registrar') }}</flux:heading>

            @forelse ($lines as $productId => $line)
                <div wire:key="summary-{{ $productId }}" class="flex justify-between text-sm">
                    <span>{{ $this->lineProducts[$productId]->name }}</span>
                    <span class="tabular-nums">+{{ $qty($line['quantity']) }}</span>
                </div>
            @empty
                <flux:text>{{ __('Agrega los productos que llegaron.') }}</flux:text>
            @endforelse

            @if ($lines !== [] && $this->supplier)
                <flux:text size="sm">
                    @if ($isLoanWarehouse)
                        {{ trans_choice('Se crea :count lote de :supplier en :warehouse.|Se crean :count lotes de :supplier en :warehouse.', count($lines), ['supplier' => $this->supplier->name, 'warehouse' => $this->warehouse->name]) }}
                    @elseif ($arrival === 'consignment')
                        {{ trans_choice('Se crea :count lote de :supplier en consigna. A :supplier se le irá debiendo conforme se venda, no antes.|Se crean :count lotes de :supplier en consigna. A :supplier se le irá debiendo conforme se vendan, no antes.', count($lines), ['supplier' => $this->supplier->name]) }}
                    @else
                        {{ trans_choice('Se crea :count lote propio comprado a :supplier.|Se crean :count lotes propios comprados a :supplier.', count($lines), ['supplier' => $this->supplier->name]) }}
                    @endif
                </flux:text>
                <div class="flex justify-between border-t border-line pt-2 font-semibold dark:border-zinc-700">
                    <span>{{ __('Total') }}</span>
                    <span class="tabular-nums">{{ $money($total) }}</span>
                </div>
            @endif

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    @foreach ($errors->all() as $message)
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    @endforeach
                </flux:callout>
            @endif

            <flux:button variant="primary" wire:click="receive" :disabled="$lines === []" data-test="receive-button">{{ __('Registrar entrada') }}</flux:button>
            <flux:text size="sm">
                {{ __('¿Llegó un producto nuevo?') }}
                <flux:link :href="route('products.create')" wire:navigate>{{ __('Dalo de alta') }}</flux:link>
                {{ __('y regresa a esta entrada.') }}
            </flux:text>
        </flux:card>
    </div>
</div>

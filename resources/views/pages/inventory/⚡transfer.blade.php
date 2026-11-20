<?php

use App\Concerns\SearchesProducts;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Traspaso entre almacenes')] class extends Component {
    use SearchesProducts;

    #[Url(as: 'origen', except: null)]
    public ?int $from_warehouse_id = null;

    public ?int $to_warehouse_id = null;
    public string $note = '';
    public string $product_search = '';

    /**
     * Lines keyed by product id.
     *
     * @var array<int, array{product_id: int, quantity: string}>
     */
    public array $lines = [];

    public function mount(): void
    {
        $this->to_warehouse_id = Warehouse::query()->where('is_sellable', true)->where('is_active', true)->value('id');
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('lender')->where('is_active', true)->orderByDesc('is_sellable')->orderBy('id')->get();
    }

    /**
     * Product goes back into a lender's warehouse only as a repayment (Préstamos).
     */
    #[Computed]
    public function destinations(): Collection
    {
        return $this->warehouses->reject(fn (Warehouse $warehouse) => $warehouse->isLoanWarehouse());
    }

    #[Computed]
    public function from(): ?Warehouse
    {
        return $this->warehouses->firstWhere('id', $this->from_warehouse_id);
    }

    #[Computed]
    public function to(): ?Warehouse
    {
        return $this->warehouses->firstWhere('id', $this->to_warehouse_id);
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
     * @return array{from: array<int, string>, to: array<int, string>}
     */
    #[Computed]
    public function stock(): array
    {
        return [
            'from' => $this->stockIn($this->from, array_keys($this->lines)),
            'to' => $this->stockIn($this->to, array_keys($this->lines)),
        ];
    }

    #[Computed]
    public function pendingLoans(): Collection
    {
        return app(InventoryService::class)->pendingLoans()->take(5);
    }

    public function addProduct(int $productId): void
    {
        $this->lines[$productId] ??= ['product_id' => $productId, 'quantity' => '1'];
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

    public function transfer(InventoryService $inventory): void
    {
        $this->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $inventory->transfer(Auth::user(), $this->from, $this->to, array_values($this->lines), $this->note);

        $this->reset('lines', 'note', 'product_search');
        unset($this->stock, $this->pendingLoans);

        Flux::toast(variant: 'success', text: __('Traspaso registrado.'));
    }
}; ?>

@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $owner = fn (?Warehouse $warehouse) => $warehouse?->isLoanWarehouse() ? $warehouse->lender?->name : config('app.name');
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('inventory.index')" wire:navigate>{{ __('Inventario') }}</flux:button>
        <flux:heading size="xl" class="mt-2">{{ __('Nuevo traspaso') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Un solo registro: descuenta en el origen y suma en el destino, sin cambiar el código del producto.') }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="flex flex-col gap-4 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <flux:select wire:model.live="from_warehouse_id" :label="__('Origen')" :placeholder="__('Elige…')">
                        @foreach ($this->warehouses as $warehouse)
                            <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($this->from)
                        <flux:text size="sm" class="mt-1">{{ __('Dueño del producto: :owner', ['owner' => $owner($this->from)]) }}</flux:text>
                    @endif
                </div>
                <div>
                    <flux:select wire:model.live="to_warehouse_id" :label="__('Destino')">
                        @foreach ($this->destinations as $warehouse)
                            <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($this->to)
                        <flux:text size="sm" class="mt-1">{{ __('Dueño del producto: :owner', ['owner' => $owner($this->to)]) }}</flux:text>
                    @endif
                </div>
            </div>

            @if ($this->from?->isLoanWarehouse())
                <flux:callout icon="arrow-uturn-left" :heading="__('Préstamo de :lender: se repone en especie después.', ['lender' => $this->from->lender?->name])" />
            @endif

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
                        <flux:table.column align="end">{{ __('En origen') }}</flux:table.column>
                        <flux:table.column>{{ __('Cantidad') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Queda en origen') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Queda en destino') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($lines as $productId => $line)
                            @php
                                $product = $this->lineProducts[$productId];
                                $inOrigin = (float) ($this->stock['from'][$productId] ?? 0);
                                $leftInOrigin = $inOrigin - (float) $line['quantity'];
                            @endphp
                            <flux:table.row :key="'line-'.$productId">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $product->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ $product->sku }}</span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $qty($inOrigin) }}</flux:table.cell>
                                <flux:table.cell class="w-28"><flux:input wire:model.live.debounce.400ms="lines.{{ $productId }}.quantity" type="number" step="any" min="0" size="sm" /></flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums {{ $leftInOrigin < 0 ? 'font-medium text-red-700 dark:text-red-400' : '' }}">{{ $leftInOrigin < 0 ? __('No alcanza') : $qty($leftInOrigin) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $qty((float) ($this->stock['to'][$productId] ?? 0) + (float) $line['quantity']) }}</flux:table.cell>
                                <flux:table.cell><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeLine({{ $productId }})" :aria-label="__('Quitar')" /></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            <flux:textarea wire:model="note" :label="__('Nota (obligatoria)')" rows="2" :placeholder="__('Para qué se mueve el producto')" />
        </flux:card>

        <div class="flex flex-col gap-6">
            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Qué se va a registrar') }}</flux:heading>
                @forelse ($lines as $productId => $line)
                    <div wire:key="summary-{{ $productId }}" class="text-sm">
                        <div class="font-medium">{{ $this->lineProducts[$productId]->name }}</div>
                        <div class="flex justify-between"><span>{{ $this->from?->name }}</span><span class="tabular-nums">−{{ $qty($line['quantity']) }}</span></div>
                        <div class="flex justify-between"><span>{{ $this->to?->name }}</span><span class="tabular-nums">+{{ $qty($line['quantity']) }}</span></div>
                    </div>
                @empty
                    <flux:text>{{ __('Elige el origen y agrega los productos.') }}</flux:text>
                @endforelse

                @if ($lines !== [] && $this->from?->isLoanWarehouse())
                    <flux:text size="sm">
                        {{ __('Queda pendiente reponer :products a :lender.', [
                            'products' => collect($lines)->map(fn ($line, $productId) => $qty($line['quantity']).' '.$this->lineProducts[$productId]->name)->join(', '),
                            'lender' => $this->from->lender?->name,
                        ]) }}
                    </flux:text>
                @endif

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        @foreach ($errors->all() as $message)
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        @endforeach
                    </flux:callout>
                @endif

                <flux:button variant="primary" wire:click="transfer" :disabled="$lines === []" data-test="transfer-button">{{ __('Registrar traspaso') }}</flux:button>
            </flux:card>

            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Reposiciones pendientes') }}</flux:heading>
                @forelse ($this->pendingLoans as $loan)
                    <div wire:key="loan-{{ $loan->id }}" class="flex justify-between text-sm">
                        <span>{{ $loan->lender->name }} · {{ $loan->product->name }} · {{ __('desde :date', ['date' => $loan->created_at->translatedFormat('j M')]) }}</span>
                        <span class="font-medium tabular-nums">{{ $qty($loan->pending_quantity) }}</span>
                    </div>
                @empty
                    <flux:text size="sm">{{ __('No hay préstamos por reponer.') }}</flux:text>
                @endforelse
                <flux:button size="sm" variant="ghost" :href="route('inventory.loans')" wire:navigate>{{ __('Ver todos los préstamos') }}</flux:button>
            </flux:card>
        </div>
    </div>
</div>

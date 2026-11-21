<?php

use App\Http\Controllers\ProductLabelController;
use App\Models\Product;
use App\Services\Ean13;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Etiquetas')] class extends Component {
    public string $search = '';

    /** Show only products without a factory barcode (none, or an internal one). */
    public bool $only_without_code = true;

    /** @var array<int, int> copies by product id. */
    public array $selected = [];

    public string $format = '50x25';

    #[Computed]
    public function products(): Collection
    {
        $term = trim($this->search);

        return Product::query()
            ->where('is_active', true)
            ->when($this->only_without_code, fn ($products) => $products->where(fn ($products) => $products->whereNull('barcode')->orWhere('barcode', 'like', '200%')))
            ->when($term !== '', fn ($products) => $products->where(fn ($products) => $products->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")->orWhere('barcode', $term)))
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function selectedProducts(): Collection
    {
        return Product::query()->whereKey(array_keys($this->selected))->orderBy('name')->get();
    }

    public function add(int $productId): void
    {
        $this->selected[$productId] = ($this->selected[$productId] ?? 0) + 1;
        unset($this->selectedProducts);
    }

    public function remove(int $productId): void
    {
        unset($this->selected[$productId]);
        unset($this->selectedProducts);
    }

    public function updatedSelected(): void
    {
        $this->selected = collect($this->selected)->map(fn ($copies) => max(1, min(500, (int) $copies)))->all();
    }

    /**
     * Give an internal EAN-13 code to the chosen products that have none that can be printed.
     */
    public function generateCodes(Ean13 $ean13): void
    {
        $generated = DB::transaction(function () use ($ean13): int {
            $products = Product::query()->whereKey(array_keys($this->selected))->lockForUpdate()->get()
                ->filter(fn (Product $product) => $ean13->normalize($product->barcode) === null && blank($product->barcode));

            foreach ($products as $product) {
                $product->update(['barcode' => Product::newInternalBarcode()]);
            }

            return $products->count();
        });

        unset($this->selectedProducts, $this->products);
        Flux::toast(variant: 'success', text: trans_choice(':count código interno generado.|:count códigos internos generados.', $generated));
    }

    #[Computed]
    public function printUrl(): ?string
    {
        return $this->selected === [] ? null : route('products.labels', ['p' => $this->selected, 'formato' => $this->format]);
    }
}; ?>

@php
    $ean13 = app(\App\Services\Ean13::class);
    $withoutPrintableCode = $this->selectedProducts->filter(fn ($product) => $ean13->normalize($product->barcode) === null);
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('inventory.index')" wire:navigate>{{ __('Inventario') }}</flux:button>
        <flux:heading size="xl" class="mt-2">{{ __('Etiquetas con código de barras') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Para los productos que no traen código de fábrica: se les genera uno interno y se imprime su etiqueta con precio.') }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="flex flex-col gap-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre, clave o código')" />
            <flux:switch wire:model.live="only_without_code" :label="__('Solo productos sin código de fábrica')" />
            <ul class="flex flex-col divide-y divide-line dark:divide-zinc-700">
                @forelse ($this->products as $product)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="product-{{ $product->id }}">
                        <div>
                            <span class="font-medium">{{ $product->name }}</span>
                            <span class="block text-sm text-zinc-500">{{ $product->sku }} · {{ $product->barcode ?? __('sin código') }}</span>
                        </div>
                        <flux:button size="xs" icon="plus" wire:click="add({{ $product->id }})">{{ __('Agregar') }}</flux:button>
                    </li>
                @empty
                    <li class="py-2"><flux:text>{{ __('No hay productos con esos filtros.') }}</flux:text></li>
                @endforelse
            </ul>
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:heading>{{ __('Por imprimir') }}</flux:heading>
            @forelse ($this->selectedProducts as $product)
                <div class="flex items-center justify-between gap-3" wire:key="selected-{{ $product->id }}">
                    <div>
                        <span class="text-sm font-medium">{{ $product->name }}</span>
                        @if ($ean13->normalize($product->barcode) === null)
                            <span class="block text-xs text-red-700 dark:text-red-400">{{ $product->barcode ? __('Su código no es EAN-13; no se puede dibujar') : __('Sin código') }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-20"><flux:input wire:model.blur="selected.{{ $product->id }}" type="number" min="1" max="500" size="sm" :aria-label="__('Etiquetas de :product', ['product' => $product->name])" /></div>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $product->id }})" :aria-label="__('Quitar')" />
                    </div>
                </div>
            @empty
                <flux:text size="sm">{{ __('Agrega productos de la lista.') }}</flux:text>
            @endforelse

            @if ($withoutPrintableCode->contains(fn ($product) => blank($product->barcode)))
                <flux:button icon="qr-code" wire:click="generateCodes" data-test="generate-codes-button">{{ __('Generar códigos internos') }}</flux:button>
            @endif

            <flux:radio.group wire:model.live="format" :label="__('Formato')">
                @foreach (\App\Http\Controllers\ProductLabelController::FORMATS as $key => $option)
                    <flux:radio :value="$key" :label="$option['label']" />
                @endforeach
            </flux:radio.group>

            @if ($this->printUrl)
                <flux:button variant="primary" icon="printer" :href="$this->printUrl" target="_blank" data-test="print-labels-button">
                    {{ trans_choice('Imprimir :count etiqueta|Imprimir :count etiquetas', array_sum($selected)) }}
                </flux:button>
            @endif
        </flux:card>
    </div>
</div>

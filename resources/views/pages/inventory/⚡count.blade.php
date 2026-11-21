<?php

use App\Models\PhysicalCount;
use App\Models\PhysicalCountItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\PhysicalCountService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Conteo físico')] class extends Component {
    #[Url(as: 'almacen')]
    public ?int $warehouse_id = null;

    public string $search = '';

    /** @var array<int, string> counted quantity by product id, as typed. */
    public array $counted = [];

    public function mount(): void
    {
        $this->warehouse_id ??= $this->warehouses->firstWhere('is_sellable', true)?->id ?? $this->warehouses->first()?->id;
        $this->loadCounted();
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_sellable')->orderBy('name')->get();
    }

    #[Computed]
    public function warehouse(): ?Warehouse
    {
        return $this->warehouses->firstWhere('id', $this->warehouse_id);
    }

    #[Computed]
    public function count(): ?PhysicalCount
    {
        return $this->warehouse ? app(PhysicalCountService::class)->inProgress($this->warehouse)?->load('startedBy') : null;
    }

    /**
     * Items of the count with what the system holds right now (what applying will compare to).
     *
     * @return Collection<int, array{item: PhysicalCountItem, system: string, counted: string|null, difference: string|null}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if ($this->count === null) {
            return collect();
        }

        $stock = Stock::query()->where('warehouse_id', $this->count->warehouse_id)->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity')->pluck('quantity', 'product_id');

        return $this->count->items()->with('product')->get()
            ->sortBy(fn (PhysicalCountItem $item) => $item->product->name)
            ->map(function (PhysicalCountItem $item) use ($stock): array {
                $system = bcadd((string) ($stock[$item->product_id] ?? '0'), '0', 3);

                return [
                    'item' => $item,
                    'system' => $system,
                    'counted' => $item->counted_quantity,
                    'difference' => $item->counted_quantity === null ? null : bcsub($item->counted_quantity, $system, 3),
                ];
            })
            ->values();
    }

    #[Computed]
    public function differences(): Collection
    {
        return $this->rows->filter(fn (array $row) => $row['difference'] !== null && bccomp($row['difference'], '0', 3) !== 0)->values();
    }

    /**
     * Products not on the list yet that match the search (found on the shelf).
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function extraProducts(): Collection
    {
        $term = trim($this->search);

        if ($this->count === null || mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()
            ->where('is_active', true)
            ->whereNotIn('id', $this->rows->map(fn (array $row) => $row['item']->product_id))
            ->where(fn ($products) => $products->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")->orWhere('barcode', $term))
            ->limit(5)
            ->get();
    }

    public function updatedWarehouseId(): void
    {
        unset($this->warehouse, $this->count, $this->rows, $this->differences);
        $this->loadCounted();
    }

    public function start(PhysicalCountService $counts): void
    {
        $counts->start(Auth::user(), $this->warehouse);
        unset($this->count, $this->rows, $this->differences);
        $this->loadCounted();
    }

    /**
     * Each typed quantity is saved right away, so the count can be continued later.
     */
    public function updatedCounted(mixed $value, string $productId): void
    {
        $value = trim((string) $value);

        app(PhysicalCountService::class)->record($this->count, Product::query()->findOrFail((int) $productId), $value === '' ? null : $value);
        unset($this->rows, $this->differences);
    }

    public function addProduct(int $productId): void
    {
        app(PhysicalCountService::class)->record($this->count, Product::query()->findOrFail($productId), null);
        $this->search = '';
        unset($this->rows, $this->differences, $this->extraProducts);
    }

    public function saveForLater(): void
    {
        Flux::toast(variant: 'success', text: __('Conteo guardado. Puedes seguir cuando quieras.'));
        $this->redirectRoute('inventory.index', navigate: true);
    }

    public function apply(PhysicalCountService $counts): void
    {
        $adjustments = $this->differences->count();
        $counts->apply(Auth::user(), $this->count);

        Flux::modal('apply-count')->close();
        Flux::toast(variant: 'success', text: trans_choice('Conteo aplicado: :count ajuste en el kardex.|Conteo aplicado: :count ajustes en el kardex.', $adjustments));
        unset($this->count, $this->rows, $this->differences);
        $this->counted = [];
    }

    public function cancelCount(PhysicalCountService $counts): void
    {
        $counts->cancel($this->count);
        Flux::modal('cancel-count')->close();
        unset($this->count, $this->rows, $this->differences);
        $this->counted = [];
    }

    private function loadCounted(): void
    {
        $this->counted = $this->rows->mapWithKeys(fn (array $row) => [
            $row['item']->product_id => $row['counted'] === null ? '' : $this->format($row['counted']),
        ])->all();
    }

    /**
     * A quantity without trailing zeros: 12.000 → 12, 2.500 → 2.5.
     */
    public function format(string $quantity): string
    {
        return str_contains($quantity, '.') ? (rtrim(rtrim($quantity, '0'), '.') ?: '0') : $quantity;
    }
}; ?>

@php
    $money = fn ($value) => ((float) $value < 0 ? '−' : '+').'$'.number_format(abs((float) $value), 2);
    $rows = $this->rows;
    $countedRows = $rows->whereNotNull('counted');
    $term = mb_strtolower(trim($search));
    $visibleRows = $term === '' ? $rows : $rows->filter(fn ($row) => str_contains(mb_strtolower($row['item']->product->name.' '.$row['item']->product->sku.' '.$row['item']->product->barcode), $term));
    $costOf = fn ($row) => bcmul($row['difference'], (string) ($row['item']->product->reference_cost ?? '0'), 2);
    $totalCost = $this->differences->reduce(fn ($carry, $row) => bcadd($carry, $costOf($row), 2), '0.00');
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:link :href="route('inventory.index')" wire:navigate class="text-sm print:hidden">← {{ __('Inventario') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="xl">{{ __('Conteo físico') }}</flux:heading>
                @if ($this->count)
                    <flux:text class="mt-1">
                        {{ __('Iniciado :when por :user', [
                            'when' => $this->count->started_at->isToday() ? __('hoy a las :time', ['time' => $this->count->started_at->format('H:i')]) : $this->count->started_at->translatedFormat('j M \a \l\a\s H:i'),
                            'user' => $this->count->startedBy->name,
                        ]) }}
                    </flux:text>
                @endif
            </div>
            <div class="flex flex-wrap items-end gap-2 print:hidden">
                <div class="w-64">
                    <flux:select wire:model.live="warehouse_id" :label="__('Almacén')">
                        @foreach ($this->warehouses as $warehouse)
                            <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($this->count)
                    <flux:button icon="printer" onclick="window.print()">{{ __('Imprimir hoja de conteo') }}</flux:button>
                @endif
            </div>
        </div>
    </div>

    @if (! $this->count)
        <flux:card class="flex flex-col items-start gap-3">
            <flux:heading>{{ __('No hay un conteo en curso en :warehouse', ['warehouse' => $this->warehouse?->name ?? '—']) }}</flux:heading>
            <flux:text>{{ __('Al iniciar se toma la lista de productos del almacén. Puedes guardar y seguir después; nada cambia en el inventario hasta que lo apliques.') }}</flux:text>
            <flux:error name="warehouse_id" />
            <flux:button variant="primary" icon="play" wire:click="start" data-test="start-count-button" :disabled="! $this->warehouse">{{ __('Iniciar conteo') }}</flux:button>
        </flux:card>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <flux:card class="flex flex-col gap-4 p-0 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4">
                    <flux:heading>{{ __(':counted de :total productos contados', ['counted' => $countedRows->count(), 'total' => $rows->count()]) }}</flux:heading>
                    <div class="w-full sm:w-72 print:hidden">
                        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Escanea o busca el producto')" />
                    </div>
                </div>

                @if ($this->extraProducts->isNotEmpty())
                    <div class="flex flex-wrap gap-2 px-4 print:hidden">
                        <flux:text size="sm">{{ __('No está en la lista:') }}</flux:text>
                        @foreach ($this->extraProducts as $product)
                            <flux:button size="xs" icon="plus" wire:click="addProduct({{ $product->id }})">{{ $product->name }}</flux:button>
                        @endforeach
                    </div>
                @endif

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Producto') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Según sistema') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Contado') }}</flux:table.column>
                        <flux:table.column>{{ __('Diferencia') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($visibleRows as $row)
                            @php
                                $product = $row['item']->product;
                                $difference = $row['difference'];
                            @endphp
                            <flux:table.row :key="$product->id">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $product->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ $product->sku }}</span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $this->format($row['system']) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="ms-auto w-24">
                                        <flux:input wire:model.blur="counted.{{ $product->id }}" type="number" min="0" step="any" size="sm" class="text-end" :aria-label="__('Contado de :product', ['product' => $product->name])" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($difference === null)
                                        <flux:badge size="sm" color="zinc">{{ __('Sin contar') }}</flux:badge>
                                    @elseif (bccomp($difference, '0', 3) === 0)
                                        <flux:badge size="sm" color="green">{{ __('Cuadra') }}</flux:badge>
                                    @elseif (bccomp($difference, '0', 3) < 0)
                                        <flux:badge size="sm" color="red">{{ __('Faltan :quantity', ['quantity' => $this->format(bcsub('0', $difference, 3))]) }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="amber">{{ __('Sobra :quantity', ['quantity' => $this->format($difference)]) }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                <flux:error name="counted_quantity" class="px-4 pb-4" />
            </flux:card>

            <div class="flex flex-col gap-4 print:hidden">
                <flux:card class="flex flex-col gap-3">
                    <flux:heading>{{ __('Resultado hasta ahora') }}</flux:heading>
                    @forelse ($this->differences as $row)
                        <div class="flex justify-between gap-3 text-sm">
                            <span>{{ $row['item']->product->name }}</span>
                            <span class="tabular-nums">{{ (bccomp($row['difference'], '0', 3) > 0 ? '+' : '−').$this->format(ltrim($row['difference'], '-')) }} · {{ $money($costOf($row)) }}</span>
                        </div>
                    @empty
                        <flux:text size="sm">{{ __('Todo lo contado cuadra con el sistema.') }}</flux:text>
                    @endforelse
                    @if ($this->differences->isNotEmpty())
                        <div class="flex justify-between gap-3 border-t border-line pt-2 font-semibold dark:border-zinc-700">
                            <span>{{ __('Diferencia a costo') }}</span>
                            <span class="tabular-nums">{{ $money($totalCost) }}</span>
                        </div>
                    @endif
                    <flux:text size="sm">
                        {{ trans_choice('Al aplicar se crea :count ajuste en el kardex con motivo ":reason". Lo no contado no se toca.|Al aplicar se crean :count ajustes en el kardex con motivo ":reason". Lo no contado no se toca.', $this->differences->count(), ['reason' => __('Conteo físico :date', ['date' => $this->count->started_at->translatedFormat('j M')])]) }}
                    </flux:text>
                </flux:card>

                <flux:error name="count" />
                <flux:button wire:click="saveForLater" data-test="save-count-button">{{ __('Guardar y seguir después') }}</flux:button>
                <flux:modal.trigger name="apply-count">
                    <flux:button variant="primary" class="w-full" :disabled="$countedRows->isEmpty()" data-test="apply-count-trigger">
                        {{ trans_choice('Aplicar :count ajuste|Aplicar :count ajustes', $this->differences->count()) }}
                    </flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="cancel-count">
                    <flux:button variant="ghost" size="sm">{{ __('Cancelar conteo') }}</flux:button>
                </flux:modal.trigger>
                <flux:text size="sm">{{ __('Conviene contar con el mostrador sin ventas, o volver a contar lo que se vendió mientras.') }}</flux:text>
            </div>
        </div>

        <flux:modal name="apply-count" class="md:w-96">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('¿Aplicar el conteo?') }}</flux:heading>
                <flux:text>{{ __('Se ajusta el inventario de :warehouse a lo contado. Los productos sin contar se quedan como están.', ['warehouse' => $this->warehouse->name]) }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Regresar') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="apply" data-test="apply-count-button">{{ __('Aplicar') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="cancel-count" class="md:w-96">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('¿Cancelar el conteo?') }}</flux:heading>
                <flux:text>{{ __('Se descarta lo contado; el inventario no cambia.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Regresar') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelCount">{{ __('Cancelar conteo') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>

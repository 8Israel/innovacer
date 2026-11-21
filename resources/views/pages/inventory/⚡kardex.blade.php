<?php

use App\Models\AdjustmentReason;
use App\Models\InventoryMovement;
use App\Models\InventoryOperationType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Kardex de producto')] class extends Component {
    use WithPagination;

    public Product $product;

    #[Url(as: 'almacen', except: null)]
    public ?int $warehouse_id = null;

    #[Url(as: 'tipo', except: '')]
    public string $type = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $until = '';

    // Ajuste por conteo (docs/diseno/AjusteConteo.html)
    public ?int $adjust_warehouse_id = null;
    public string $counted_quantity = '';
    public string $reason = '';
    public string $adjust_note = '';

    // Salida que no es venta
    public ?int $exit_warehouse_id = null;
    public string $exit_quantity = '';
    public string $exit_reason = '';
    public string $responsible = '';
    public string $exit_note = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('lender')->where('is_active', true)->orderByDesc('is_sellable')->orderBy('id')->get();
    }

    /**
     * Stock of the product in each warehouse, split into the business's own and
     * what belongs to someone else (a consignment supplier or the warehouse's lender).
     *
     * @return array<int, array{total: string, own: string, others: string, minimum: string|null}>
     */
    #[Computed]
    public function stockByWarehouse(): array
    {
        $rows = Stock::query()
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->where('stocks.product_id', $this->product->id)
            ->groupBy('stocks.warehouse_id')
            ->selectRaw('stocks.warehouse_id, SUM(stocks.quantity) as total, SUM(CASE WHEN lots.owner_supplier_id IS NULL THEN stocks.quantity ELSE 0 END) as own')
            ->get()
            ->keyBy('warehouse_id');
        $minimums = $this->product->stockMinimums()->pluck('min_quantity', 'warehouse_id');

        return $this->warehouses->mapWithKeys(function (Warehouse $warehouse) use ($rows, $minimums) {
            $total = (string) ($rows[$warehouse->id]->total ?? 0);
            $own = $warehouse->isLoanWarehouse() ? '0' : (string) ($rows[$warehouse->id]->own ?? 0);

            return [$warehouse->id => [
                'total' => $total,
                'own' => $own,
                'others' => (string) ((float) $total - (float) $own),
                'minimum' => $minimums[$warehouse->id] ?? null,
            ]];
        })->all();
    }

    #[Computed]
    public function movements(): LengthAwarePaginator
    {
        return app(InventoryService::class)
            ->kardex(
                $this->product,
                $this->warehouses->firstWhere('id', $this->warehouse_id),
                $this->from !== '' ? Carbon::parse($this->from)->startOfDay() : null,
                $this->until !== '' ? Carbon::parse($this->until)->endOfDay() : null,
            )
            ->with(['inventoryOperation.loanEntries', 'inventoryOperation.supplier', 'lot.ownerSupplier'])
            ->when($this->type !== '', fn (Builder $query) => $query->whereHas('inventoryOperation', fn (Builder $operation) => $operation->where('type', $this->type)))
            ->paginate(25);
    }

    /**
     * How a kardex line reads, e.g. "Venta V-000043" or "Traspaso · préstamo".
     */
    public function describe(InventoryMovement $movement): string
    {
        $operation = $movement->inventoryOperation;
        $loanEntry = $operation->loanEntries->firstWhere('product_id', $movement->product_id);

        return match ($operation->type) {
            InventoryOperationType::Sale, InventoryOperationType::Return => trim($operation->type->label().' '.$operation->reference),
            InventoryOperationType::Transfer => $loanEntry === null
                ? $operation->type->label()
                : $operation->type->label().' · '.((float) $loanEntry->quantity > 0 ? __('préstamo') : __('reposición')),
            InventoryOperationType::Exit => $operation->type->label().' · '.mb_strtolower((string) $operation->reason),
            InventoryOperationType::Adjustment => $operation->type->label().' · '.(AdjustmentReason::tryFrom((string) $operation->reason)?->label() ?? ''),
            InventoryOperationType::Entry => $operation->type->label().' · '.($movement->lot->ownerSupplier ? __('consigna') : __('propio')),
            InventoryOperationType::Reversal => trim($operation->type->label().' '.$operation->reference),
        };
    }

    public function startAdjustment(?int $warehouseId = null): void
    {
        $this->adjust_warehouse_id = $warehouseId ?? $this->warehouses->first()?->id;
        $this->reset('counted_quantity', 'reason', 'adjust_note');
        $this->resetErrorBag();
        Flux::modal('adjust-stock')->show();
    }

    public function adjust(InventoryService $inventory): void
    {
        $this->validate([
            'adjust_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reason' => ['required', Rule::in(array_map(fn (AdjustmentReason $reason) => $reason->value, AdjustmentReason::manual()))],
        ], attributes: ['reason' => __('motivo')]);

        $inventory->adjust(
            Auth::user(),
            $this->product,
            Warehouse::query()->findOrFail($this->adjust_warehouse_id),
            $this->counted_quantity,
            AdjustmentReason::from($this->reason),
            $this->adjust_note ?: null,
        );

        Flux::modal('adjust-stock')->close();
        $this->refreshKardex();
        Flux::toast(variant: 'success', text: __('Ajuste registrado.'));
    }

    public function startExit(): void
    {
        $this->exit_warehouse_id = $this->warehouses->reject(fn (Warehouse $warehouse) => $warehouse->isLoanWarehouse())->first()?->id;
        $this->reset('exit_quantity', 'exit_reason', 'responsible', 'exit_note');
        $this->resetErrorBag();
        Flux::modal('stock-exit')->show();
    }

    public function recordExit(InventoryService $inventory): void
    {
        $this->validate(['exit_warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);

        $inventory->exit(
            Auth::user(),
            Warehouse::query()->findOrFail($this->exit_warehouse_id),
            [['product_id' => $this->product->id, 'quantity' => $this->exit_quantity]],
            $this->exit_reason,
            $this->responsible,
            $this->exit_note ?: null,
        );

        Flux::modal('stock-exit')->close();
        $this->refreshKardex();
        Flux::toast(variant: 'success', text: __('Salida registrada.'));
    }

    private function refreshKardex(): void
    {
        unset($this->stockByWarehouse, $this->movements);
        $this->resetPage();
    }
}; ?>

@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $signed = fn ($value) => ((float) $value > 0 ? '+' : '−').$qty(abs((float) $value));
    $totals = collect($this->stockByWarehouse);
    $systemQuantity = $this->stockByWarehouse[$adjust_warehouse_id]['total'] ?? '0';
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('inventory.index')" wire:navigate>{{ __('Inventario') }}</flux:button>
            <flux:heading size="xl" class="mt-2">{{ $product->name }}</flux:heading>
            <flux:text class="mt-1">
                {{ collect([
                    $product->sku,
                    $product->barcode ? __('Código de barras :code', ['code' => $product->barcode]) : null,
                    $product->supplier ? __('Proveedor :supplier', ['supplier' => $product->supplier->name]) : null,
                    __('Clave SAT :key', ['key' => $product->product_key ?? __('[POR DEFINIR]')]),
                ])->filter()->join(' · ') }}
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="scale" wire:click="startAdjustment" data-test="adjust-button">{{ __('Ajuste por conteo') }}</flux:button>
            <flux:button icon="arrow-up-tray" wire:click="startExit" data-test="exit-button">{{ __('Salida') }}</flux:button>
            <flux:button icon="arrows-right-left" :href="route('inventory.transfer')" wire:navigate>{{ __('Traspasar') }}</flux:button>
            <flux:button variant="primary" icon="arrow-down-tray" :href="route('inventory.entry')" wire:navigate>{{ __('Registrar entrada') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->warehouses as $warehouse)
            @php
                $stock = $this->stockByWarehouse[$warehouse->id];
            @endphp
            <flux:card wire:key="warehouse-{{ $warehouse->id }}" class="flex flex-col gap-1">
                <flux:text size="sm">{{ $warehouse->name }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">{{ $qty($stock['total']) }}</flux:heading>
                @php
                    $ownership = match (true) {
                        $warehouse->isLoanWarehouse() => __('De :lender', ['lender' => $warehouse->lender?->name]),
                        (float) $stock['others'] > 0 => __(':own propios · :others en consigna', ['own' => $qty($stock['own']), 'others' => $qty($stock['others'])]),
                        default => __('Propio'),
                    };
                    $minimum = $stock['minimum'] !== null ? __('mínimo :minimum', ['minimum' => $qty($stock['minimum'])]) : null;
                @endphp
                <flux:text size="sm">{{ collect([$ownership, $minimum])->filter()->join(' · ') }}</flux:text>
            </flux:card>
        @endforeach
        <flux:card class="flex flex-col gap-1 border-accent">
            <flux:text size="sm">{{ __('Total físico') }}</flux:text>
            <flux:heading size="lg" class="tabular-nums">{{ $qty($totals->sum(fn ($stock) => (float) $stock['total'])) }}</flux:heading>
            <flux:text size="sm">{{ __(':own propios · :others en consigna', ['own' => $qty($totals->sum(fn ($stock) => (float) $stock['own'])), 'others' => $qty($totals->sum(fn ($stock) => (float) $stock['others']))]) }}</flux:text>
        </flux:card>
    </div>

    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <flux:heading size="lg">{{ __('Movimientos') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:select wire:model.live="warehouse_id" size="sm">
                    <flux:select.option value="">{{ __('Almacén: todos') }}</flux:select.option>
                    @foreach ($this->warehouses as $warehouse)
                        <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="type" size="sm">
                    <flux:select.option value="">{{ __('Tipo: todos') }}</flux:select.option>
                    @foreach (\App\Models\InventoryOperationType::cases() as $operationType)
                        <flux:select.option :value="$operationType->value">{{ $operationType->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model.live="from" type="date" size="sm" :aria-label="__('Desde')" />
                <flux:input wire:model.live="until" type="date" size="sm" :aria-label="__('Hasta')" />
            </div>
        </div>

        <flux:table :paginate="$this->movements">
            <flux:table.columns>
                <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                <flux:table.column>{{ __('Movimiento') }}</flux:table.column>
                <flux:table.column>{{ __('Almacén') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Cant.') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Antes') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Después') }}</flux:table.column>
                <flux:table.column>{{ __('Usuario') }}</flux:table.column>
                <flux:table.column>{{ __('Nota') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->movements as $movement)
                    @php
                        $operation = $movement->inventoryOperation;
                    @endphp
                    <flux:table.row :key="$movement->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $operation->occurred_at->translatedFormat('j M · H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $this->describe($movement) }}</flux:table.cell>
                        <flux:table.cell>{{ $movement->warehouse->name }}</flux:table.cell>
                        <flux:table.cell align="end" class="font-medium tabular-nums {{ (float) $movement->quantity < 0 ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $signed($movement->quantity) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $qty($movement->stock_before) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $qty($movement->stock_after) }}</flux:table.cell>
                        <flux:table.cell>{{ $operation->user->name }}</flux:table.cell>
                        <flux:table.cell class="max-w-64 truncate">{{ collect([$operation->responsible, $operation->note])->filter()->join(' · ') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8"><flux:text class="py-6 text-center">{{ __('No hay movimientos con esos filtros.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{-- docs/diseno/AjusteConteo.html --}}
    <flux:modal name="adjust-stock" class="md:w-[28rem]">
        <form wire:submit="adjust" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Ajuste por conteo') }}</flux:heading>
                <flux:text>{{ $product->name }}</flux:text>
            </div>

            <flux:select wire:model.live="adjust_warehouse_id" :label="__('Almacén')">
                @foreach ($this->warehouses as $warehouse)
                    <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-3 items-end gap-3">
                <div>
                    <flux:label>{{ __('El sistema dice') }}</flux:label>
                    <flux:heading size="lg" class="mt-2 tabular-nums">{{ $qty($systemQuantity) }}</flux:heading>
                </div>
                <flux:input wire:model.live.debounce.300ms="counted_quantity" :label="__('Contaste')" type="number" step="any" min="0" required />
                <div>
                    <flux:label>{{ __('Diferencia') }}</flux:label>
                    <flux:heading size="lg" class="mt-2 tabular-nums">{{ is_numeric($counted_quantity) ? $signed((float) $counted_quantity - (float) $systemQuantity) : '—' }}</flux:heading>
                </div>
            </div>

            <flux:radio.group wire:model="reason" :label="__('Motivo')">
                @foreach (\App\Models\AdjustmentReason::manual() as $adjustmentReason)
                    <flux:radio :value="$adjustmentReason->value" :label="$adjustmentReason->label()" />
                @endforeach
            </flux:radio.group>

            <flux:input wire:model="adjust_note" :label="__('Nota')" />
            <flux:text size="sm">{{ __('Quedará en el kardex como ajuste, con tu usuario y la hora. No se puede borrar, solo corregir con otro ajuste.') }}</flux:text>

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    @foreach ($errors->all() as $message)
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    @endforeach
                </flux:callout>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Registrar ajuste') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="stock-exit" class="md:w-[28rem]">
        <form wire:submit="recordExit" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Salida que no es venta') }}</flux:heading>
                <flux:text>{{ __('Muestras, experimentos o producto que se lleva alguien.') }}</flux:text>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="exit_warehouse_id" :label="__('Almacén')">
                    @foreach ($this->warehouses->reject(fn ($warehouse) => $warehouse->isLoanWarehouse()) as $warehouse)
                        <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="exit_quantity" :label="__('Cantidad')" type="number" step="any" min="0" required />
            </div>
            <flux:input wire:model="exit_reason" :label="__('Motivo')" :placeholder="__('Muestra, experimento…')" required />
            <flux:input wire:model="responsible" :label="__('Quién se lo llevó')" :placeholder="__('Ej. Ing. Meléndez')" required />
            <flux:input wire:model="exit_note" :label="__('Nota')" />

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    @foreach ($errors->all() as $message)
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    @endforeach
                </flux:callout>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Registrar salida') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

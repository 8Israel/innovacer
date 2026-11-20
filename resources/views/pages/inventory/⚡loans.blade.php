<?php

use App\Concerns\SearchesProducts;
use App\Models\LoanEntry;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Préstamos y reposiciones')] class extends Component {
    use SearchesProducts;

    public string $tab = 'pending';

    // Registrar reposición
    public ?int $loan_id = null;
    public string $quantity = '';
    public ?int $from_warehouse_id = null;
    public string $note = '';

    #[Computed]
    public function pendingLoans(): Collection
    {
        return app(InventoryService::class)->pendingLoans();
    }

    /**
     * Loans repaid in full, newest first.
     */
    #[Computed]
    public function repaidLoans(): Collection
    {
        return LoanEntry::query()
            ->with(['product', 'lender'])
            ->whereNull('repays_loan_entry_id')
            ->where('quantity', '>', 0)
            ->withSum('repayments', 'quantity')
            ->latest('id')
            ->limit(30)
            ->get()
            ->filter(fn (LoanEntry $loan) => bccomp(bcadd($loan->quantity, (string) ($loan->repayments_sum_quantity ?? 0), 3), '0', 3) <= 0)
            ->values();
    }

    /**
     * Pending pieces and their value at the products' reference cost.
     *
     * @return array{pieces: string, value: string, oldest: LoanEntry|null}
     */
    #[Computed]
    public function summary(): array
    {
        $pieces = '0';
        $value = '0';

        foreach ($this->pendingLoans as $loan) {
            $pieces = bcadd($pieces, $loan->pending_quantity, 3);
            $value = bcadd($value, bcmul($loan->pending_quantity, (string) ($loan->product->reference_cost ?? 0), 4), 4);
        }

        return ['pieces' => $pieces, 'value' => $value, 'oldest' => $this->pendingLoans->first()];
    }

    /**
     * Warehouses the repayment can leave from (not a lender's).
     */
    #[Computed]
    public function sourceWarehouses(): Collection
    {
        return Warehouse::query()->whereNull('lender_id')->where('is_active', true)->orderByDesc('is_sellable')->orderBy('id')->get();
    }

    #[Computed]
    public function loanWarehouses(): Collection
    {
        return Warehouse::query()->with('lender')->whereNotNull('lender_id')->where('is_active', true)->get();
    }

    #[Computed]
    public function selectedLoan(): ?LoanEntry
    {
        return $this->loan_id ? $this->pendingLoans->firstWhere('id', $this->loan_id) : null;
    }

    /**
     * The business's own stock of the loan's product in the chosen warehouse.
     */
    #[Computed]
    public function ownStock(): string
    {
        $warehouse = $this->sourceWarehouses->firstWhere('id', $this->from_warehouse_id);

        if ($this->selectedLoan === null || $warehouse === null) {
            return '0';
        }

        return (string) $warehouse->stocks()
            ->where('product_id', $this->selectedLoan->product_id)
            ->whereHas('lot', fn (Builder $lot) => $lot->whereNull('owner_supplier_id'))
            ->sum('quantity');
    }

    public function startRepayment(int $loanId): void
    {
        $this->loan_id = $loanId;
        $this->quantity = rtrim(rtrim($this->selectedLoan->pending_quantity, '0'), '.');
        $this->from_warehouse_id = $this->sourceWarehouses->first()?->id;
        $this->reset('note');
        $this->resetErrorBag();
        Flux::modal('repay-loan')->show();
    }

    public function repay(InventoryService $inventory): void
    {
        $this->validate([
            'loan_id' => ['required', 'integer'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $inventory->repayLoan(
            Auth::user(),
            LoanEntry::query()->findOrFail($this->loan_id),
            $this->quantity,
            Warehouse::query()->findOrFail($this->from_warehouse_id),
            $this->note ?: null,
        );

        Flux::modal('repay-loan')->close();
        $this->reset('loan_id', 'quantity', 'note');
        unset($this->pendingLoans, $this->repaidLoans, $this->summary);

        Flux::toast(variant: 'success', text: __('Reposición registrada.'));
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('inventory.transfer')" wire:navigate>{{ __('Traspasos') }}</flux:button>
            <flux:heading size="xl" class="mt-2">{{ __('Préstamos y reposiciones') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Producto tomado de consignas que se debe regresar en especie.') }}</flux:text>
        </div>
        @foreach ($this->loanWarehouses as $warehouse)
            <flux:button icon="plus" :href="route('inventory.transfer', ['origen' => $warehouse->id])" wire:navigate>{{ __('Nuevo préstamo de :lender', ['lender' => $warehouse->lender?->name]) }}</flux:button>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Por reponer') }}</flux:text>
            <flux:heading size="lg">{{ trans_choice(':count pieza|:count piezas', (int) $this->summary['pieces'], ['count' => $qty($this->summary['pieces'])]) }}</flux:heading>
            <flux:text size="sm">{{ __(':value a costo de referencia', ['value' => $money($this->summary['value'])]) }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Préstamo más viejo') }}</flux:text>
            @if ($this->summary['oldest'])
                <flux:heading size="lg">{{ trans_choice(':count día|:count días', (int) $this->summary['oldest']->created_at->diffInDays(now())) }}</flux:heading>
                <flux:text size="sm">{{ $this->summary['oldest']->product->name }}, {{ __('desde :date', ['date' => $this->summary['oldest']->created_at->translatedFormat('j M')]) }}</flux:text>
            @else
                <flux:heading size="lg">—</flux:heading>
            @endif
        </flux:card>
    </div>

    <flux:radio.group wire:model.live="tab" variant="segmented" size="sm" class="w-fit">
        <flux:radio value="pending" :label="__('Pendientes (:count)', ['count' => $this->pendingLoans->count()])" />
        <flux:radio value="repaid" :label="__('Repuestos')" />
    </flux:radio.group>

    <flux:card class="p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                <flux:table.column>{{ __('Producto') }}</flux:table.column>
                <flux:table.column>{{ __('De') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Prestado') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Repuesto') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Falta') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($tab === 'pending' ? $this->pendingLoans : $this->repaidLoans as $loan)
                    @php
                        $repaid = bcsub('0', (string) ($loan->repayments_sum_quantity ?? 0), 3);
                        $pending = bcsub($loan->quantity, $repaid, 3);
                    @endphp
                    <flux:table.row :key="$loan->id">
                        <flux:table.cell>{{ $loan->created_at->translatedFormat('j M') }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="font-medium">{{ $loan->product->name }}</span>
                            @if ($loan->note)
                                <span class="block text-sm text-zinc-500">{{ $loan->note }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $loan->lender->name }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $qty($loan->quantity) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $qty($repaid) }}</flux:table.cell>
                        <flux:table.cell align="end" class="font-medium tabular-nums">
                            {{ $qty($pending) }}
                            @if ($tab === 'pending' && bccomp($repaid, '0', 3) === 1)
                                <flux:badge size="sm" color="amber" class="ms-1">{{ __('parcial') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($tab === 'pending')
                                <flux:button size="sm" variant="primary" wire:click="startRepayment({{ $loan->id }})">{{ __('Registrar reposición') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <flux:text class="py-6 text-center">{{ $tab === 'pending' ? __('No hay préstamos por reponer.') : __('Todavía no hay préstamos repuestos.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="repay-loan" class="md:w-[28rem]">
        <form wire:submit="repay" class="flex flex-col gap-4">
            @if ($this->selectedLoan)
                <div>
                    <flux:heading size="lg">{{ __('Registrar reposición') }}</flux:heading>
                    <flux:text>{{ __('Préstamo :product · faltan :pending', ['product' => $this->selectedLoan->product->name, 'pending' => $qty($this->selectedLoan->pending_quantity)]) }}</flux:text>
                </div>

                <flux:input wire:model.live.debounce.300ms="quantity" :label="__('Cantidad que regresa')" type="number" step="any" min="0" required />

                <flux:select wire:model.live="from_warehouse_id" :label="__('Sale de')">
                    @foreach ($this->sourceWarehouses as $warehouse)
                        <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text size="sm">{{ __('Hay :quantity propios para reponer.', ['quantity' => $qty($this->ownStock)]) }}</flux:text>

                <flux:input wire:model="note" :label="__('Nota')" />

                @if (is_numeric($quantity))
                    <flux:text size="sm">
                        {{ bccomp(bcadd($quantity, '0', 3), $this->selectedLoan->pending_quantity, 3) === 0 ? __('Este préstamo queda saldado.') : __('Quedarán :left por reponer.', ['left' => $qty(bcsub($this->selectedLoan->pending_quantity, bcadd($quantity, '0', 3), 3))]) }}
                    </flux:text>
                @endif

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        @foreach ($errors->all() as $message)
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        @endforeach
                    </flux:callout>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="repay-button">{{ __('Registrar reposición') }}</flux:button>
                </div>
            @endif
        </form>
    </flux:modal>
</div>

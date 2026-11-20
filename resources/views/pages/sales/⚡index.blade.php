<?php

use App\Models\PaymentMethod;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Ventas')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $until = '';

    /** '' all, a payment method, or 'credit' for sales left (partly) on credit. */
    #[Url(as: 'pago', except: '')]
    public string $payment = '';

    /** '' all, 'paid', 'receivable', 'returned' or 'canceled'. */
    #[Url(as: 'estado', except: '')]
    public string $status = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function sales(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Sale::query()
            ->with(['customer', 'customerDestination', 'warehouse', 'payments'])
            ->where('status', '!=', SaleStatus::Pending)
            ->withSum('payments', 'amount')
            ->withSum('paymentAllocations', 'amount')
            ->withSum(['customerReturns as credited_returns_sum' => fn (Builder $returns) => $returns->where('refund_method', RefundMethod::AccountCredit)], 'refund_amount')
            ->withCount('customerReturns')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('folio', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$term}%"))
                ->orWhereHas('customerDestination', fn (Builder $destination) => $destination->where('name', 'like', "%{$term}%"))))
            ->when($this->from !== '', fn (Builder $query) => $query->where('sold_at', '>=', $this->from.' 00:00:00'))
            ->when($this->until !== '', fn (Builder $query) => $query->where('sold_at', '<=', $this->until.' 23:59:59'))
            ->when(in_array($this->payment, ['cash', 'card', 'transfer'], true), fn (Builder $query) => $query->whereHas('payments', fn (Builder $payments) => $payments->where('method', $this->payment)))
            ->when($this->payment === 'credit', fn (Builder $query) => $query->whereRaw('total > (select coalesce(sum(amount), 0) from sale_payments where sale_payments.sale_id = sales.id)'))
            ->when($this->status === 'canceled', fn (Builder $query) => $query->where('status', SaleStatus::Canceled))
            ->when($this->status === 'returned', fn (Builder $query) => $query->has('customerReturns'))
            ->when(in_array($this->status, ['paid', 'receivable'], true), fn (Builder $query) => $query
                ->where('status', SaleStatus::Completed)
                ->whereRaw($this->pendingSql().($this->status === 'receivable' ? ' > 0.004' : ' <= 0.004'), [RefundMethod::AccountCredit->value]))
            ->latest('sold_at')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * What a sale still owes, in SQL: total − sale payments − customer payment
     * allocations − returns credited to the account (bound as the only parameter).
     */
    private function pendingSql(): string
    {
        return 'sales.total'
            .' - (select coalesce(sum(amount), 0) from sale_payments where sale_payments.sale_id = sales.id)'
            .' - (select coalesce(sum(amount), 0) from customer_payment_allocations where customer_payment_allocations.sale_id = sales.id)'
            .' - (select coalesce(sum(refund_amount), 0) from customer_returns where customer_returns.sale_id = sales.id and customer_returns.refund_method = ?)';
    }

    /**
     * @return array{label: string, color: string}
     */
    public function statusOf(Sale $sale): array
    {
        if ($sale->isCanceled()) {
            return ['label' => __('Cancelada'), 'color' => 'zinc'];
        }

        if ($sale->customer_returns_count > 0) {
            return ['label' => __('Devolución parcial'), 'color' => 'amber'];
        }

        $pending = (float) $sale->total
            - (float) $sale->payments_sum_amount
            - (float) $sale->payment_allocations_sum_amount
            - (float) $sale->credited_returns_sum;

        return $pending > 0.004
            ? ['label' => __('Por cobrar'), 'color' => 'red']
            : ['label' => __('Pagada'), 'color' => 'green'];
    }

    /**
     * How the sale was paid: one method, "Mixto", or "A crédito" when nothing was paid.
     */
    public function paymentOf(Sale $sale): string
    {
        $methods = $sale->payments->pluck('method')->unique();

        return match ($methods->count()) {
            0 => __('A crédito'),
            1 => $methods->first()->label(),
            default => __('Mixto'),
        };
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Ventas') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Busca cualquier venta para verla, reimprimirla o registrar una devolución.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('pos.index')" wire:navigate>{{ __('Nueva venta') }}</flux:button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Folio, cliente o destino')" />
        <flux:input wire:model.live="from" type="date" :aria-label="__('Desde')" />
        <flux:input wire:model.live="until" type="date" :aria-label="__('Hasta')" />
        <flux:select wire:model.live="payment">
            <flux:select.option value="">{{ __('Pago: todos') }}</flux:select.option>
            @foreach (\App\Models\PaymentMethod::cases() as $method)
                <flux:select.option :value="$method->value">{{ $method->label() }}</flux:select.option>
            @endforeach
            <flux:select.option value="credit">{{ __('A crédito') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="status">
            <flux:select.option value="">{{ __('Estado: todos') }}</flux:select.option>
            <flux:select.option value="paid">{{ __('Pagada') }}</flux:select.option>
            <flux:select.option value="receivable">{{ __('Por cobrar') }}</flux:select.option>
            <flux:select.option value="returned">{{ __('Con devolución') }}</flux:select.option>
            <flux:select.option value="canceled">{{ __('Cancelada') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:card class="p-0">
        <flux:table :paginate="$this->sales">
            <flux:table.columns>
                <flux:table.column>{{ __('Folio') }}</flux:table.column>
                <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                <flux:table.column>{{ __('Cliente') }}</flux:table.column>
                <flux:table.column>{{ __('Destino') }}</flux:table.column>
                <flux:table.column>{{ __('Pago') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                <flux:table.column>{{ __('Estado') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->sales as $sale)
                    @php
                        $status = $this->statusOf($sale);
                    @endphp
                    <flux:table.row :key="$sale->id">
                        <flux:table.cell><flux:link :href="route('sales.show', $sale)" wire:navigate class="font-medium">{{ $sale->folio }}</flux:link></flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $sale->sold_at->translatedFormat('j M · H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $sale->customer?->name ?? __('Público en general') }}</flux:table.cell>
                        <flux:table.cell>{{ $sale->customerDestination?->name ?? $sale->warehouse->name }}</flux:table.cell>
                        <flux:table.cell>{{ $this->paymentOf($sale) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $money($sale->total) }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7"><flux:text class="py-6 text-center">{{ __('No hay ventas con esos filtros.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>

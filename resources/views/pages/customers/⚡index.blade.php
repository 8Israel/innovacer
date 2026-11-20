<?php

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Services\CustomerBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Clientes')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' all, 'cash' or 'credit'. */
    #[Url(as: 'tipo', except: '')]
    public string $type = '';

    #[Url(as: 'con_saldo', except: true)]
    public bool $only_with_balance = true;

    /**
     * Every active customer with their balance, last payment and days without paying.
     *
     * @return Collection<int, array{customer: Customer, balance: string, last_payment_at: Carbon|null, days_without_payment: int|null}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $balances = app(CustomerBalanceService::class);
        $lastPayments = CustomerPayment::query()
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(paid_at) as last_paid_at')
            ->pluck('last_paid_at', 'customer_id');

        return Customer::query()
            ->with('destinations')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) use ($balances, $lastPayments): array {
                $balance = $balances->balance($customer);
                $lastPaymentAt = isset($lastPayments[$customer->id]) ? Carbon::parse($lastPayments[$customer->id]) : null;
                $owedSince = $lastPaymentAt ?? $balances->salesWithBalance($customer, null, allAccounts: true)->first()?->sold_at;

                return [
                    'customer' => $customer,
                    'balance' => $balance,
                    'last_payment_at' => $lastPaymentAt,
                    'days_without_payment' => bccomp($balance, '0', 2) === 1 && $owedSince ? (int) $owedSince->diffInDays(now()) : null,
                ];
            });
    }

    #[Computed]
    public function filteredRows(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return $this->rows
            ->when($term !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['customer']->name.' '.$row['customer']->rfc.' '.$row['customer']->phone), $term)))
            ->when($this->type === 'credit', fn (Collection $rows) => $rows->filter(fn (array $row) => $row['customer']->hasCredit()))
            ->when($this->type === 'cash', fn (Collection $rows) => $rows->reject(fn (array $row) => $row['customer']->hasCredit()))
            ->when($this->only_with_balance, fn (Collection $rows) => $rows->filter(fn (array $row) => bccomp($row['balance'], '0', 2) === 1))
            ->sortByDesc(fn (array $row) => (float) $row['balance'])
            ->values();
    }

    /**
     * @return array{receivable: string, debtors: int, collected: string, payments: int, overdue: string, overdue_customers: int}
     */
    #[Computed]
    public function summary(): array
    {
        $debtors = $this->rows->filter(fn (array $row) => bccomp($row['balance'], '0', 2) === 1);
        $overdue = $debtors->filter(fn (array $row) => ($row['days_without_payment'] ?? 0) > 30);
        $monthPayments = CustomerPayment::query()->where('paid_at', '>=', now()->startOfMonth());

        return [
            'receivable' => $debtors->reduce(fn (string $carry, array $row) => bcadd($carry, $row['balance'], 2), '0'),
            'debtors' => $debtors->count(),
            'collected' => (string) (clone $monthPayments)->sum('amount'),
            'payments' => (clone $monthPayments)->count(),
            'overdue' => $overdue->reduce(fn (string $carry, array $row) => bcadd($carry, $row['balance'], 2), '0'),
            'overdue_customers' => $overdue->count(),
        ];
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Clientes') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Saldos, cuentas y abonos de cada cliente') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>{{ __('Nuevo cliente') }}</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Por cobrar') }}</flux:text>
            <flux:heading size="lg">{{ $money($this->summary['receivable']) }}</flux:heading>
            <flux:text size="sm">{{ trans_choice(':count cliente con saldo|:count clientes con saldo', $this->summary['debtors']) }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Cobrado este mes') }}</flux:text>
            <flux:heading size="lg">{{ $money($this->summary['collected']) }}</flux:heading>
            <flux:text size="sm">{{ trans_choice(':count abono desde el :date|:count abonos desde el :date', $this->summary['payments'], ['date' => now()->startOfMonth()->translatedFormat('j M')]) }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Más de 30 días sin abonar') }}</flux:text>
            <flux:heading size="lg">{{ trans_choice(':count cliente|:count clientes', $this->summary['overdue_customers']) }}</flux:heading>
            <flux:text size="sm">{{ __(':amount pendientes', ['amount' => $money($this->summary['overdue'])]) }}</flux:text>
        </flux:card>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="w-full sm:w-72">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre, RFC o teléfono')" />
        </div>
        <flux:radio.group wire:model.live="type" variant="segmented" size="sm">
            <flux:radio value="" :label="__('Todos')" />
            <flux:radio value="credit" :label="__('Crédito')" />
            <flux:radio value="cash" :label="__('Contado')" />
        </flux:radio.group>
        <flux:switch wire:model.live="only_with_balance" :label="__('Solo con saldo')" />
    </div>

    <flux:card class="p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Cliente') }}</flux:table.column>
                <flux:table.column>{{ __('Tipo') }}</flux:table.column>
                <flux:table.column>{{ __('Cuentas') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Saldo') }}</flux:table.column>
                <flux:table.column>{{ __('Último abono') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Días sin abonar') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->filteredRows as $row)
                    @php
                        $customer = $row['customer'];
                        $detail = match (true) {
                            bccomp($customer->discount_rate, '0', 4) === 1 => __('Descuento fijo :rate%', ['rate' => rtrim(rtrim(bcmul($customer->discount_rate, '100', 2), '0'), '.')]),
                            $customer->credit_limit !== null => __('Límite :limit', ['limit' => $money($customer->credit_limit)]),
                            default => __('Tel. :phone', ['phone' => $customer->phone ?? __('[POR CAPTURAR]')]),
                        };
                    @endphp
                    <flux:table.row :key="$customer->id">
                        <flux:table.cell>
                            <flux:link :href="route('customers.show', $customer)" wire:navigate class="font-medium">{{ $customer->name }}</flux:link>
                            <span class="block text-sm text-zinc-500">{{ $detail }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$customer->hasCredit() ? 'blue' : 'zinc'">{{ $customer->hasCredit() ? __('Crédito') : __('Contado') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $customer->destinations->isEmpty() ? __('1 cuenta') : $customer->destinations->pluck('name')->join(', ') }}</flux:table.cell>
                        <flux:table.cell align="end" class="font-medium tabular-nums">{{ $money($row['balance']) }}</flux:table.cell>
                        <flux:table.cell>{{ $row['last_payment_at']?->translatedFormat('j M') ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums {{ ($row['days_without_payment'] ?? 0) > 30 ? 'font-medium text-red-700 dark:text-red-400' : '' }}">{{ $row['days_without_payment'] ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6"><flux:text class="py-6 text-center">{{ __('No hay clientes con esos filtros.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:text size="sm">
        {{ __('Mostrando :shown de :total clientes', ['shown' => $this->filteredRows->count(), 'total' => $this->rows->count()]) }}
        @if ($only_with_balance)
            · {{ __('quita "Solo con saldo" para ver los de contado') }}
        @endif
    </flux:text>
</div>

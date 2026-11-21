<?php

use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Services\CashSessionService;
use App\Services\CustomerBalanceService;
use App\Services\CustomerPaymentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Estado de cuenta')] class extends Component {
    public Customer $customer;

    /** Movements of one account only; null shows all. */
    #[Url(as: 'cuenta', except: '')]
    public string $ledger_destination_id = '';

    // Registrar abono
    public ?int $customer_destination_id = null;
    public string $start_sale_id = '';
    public string $amount = '';
    public string $method = 'cash';
    public string $reference = '';
    public string $note = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer->load('destinations');
        // Start on the first account that owes something; "Sin cuenta" when only sales without one do.
        $owing = $this->accounts->first(fn (array $account) => bccomp($account['balance'], '0', 2) === 1);
        $this->customer_destination_id = $owing
            ? $owing['destination']->id
            : (bccomp($this->unassignedBalance, '0', 2) === 1 ? null : $this->customer->destinations->first()?->id);
    }

    #[Computed]
    public function balance(): string
    {
        return app(CustomerBalanceService::class)->balance($this->customer);
    }

    /**
     * @return Collection<int, array{destination: CustomerDestination, balance: string, open_sales: int}>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $balances = app(CustomerBalanceService::class);

        return $this->customer->destinations->map(fn (CustomerDestination $destination) => [
            'destination' => $destination,
            'balance' => $balances->destinationBalance($destination),
            'open_sales' => $balances->salesWithBalance($this->customer, $destination)->count(),
        ]);
    }

    /**
     * What is owed on sales charged without choosing an account, so it can be paid too.
     */
    #[Computed]
    public function unassignedBalance(): string
    {
        return app(CustomerBalanceService::class)->salesWithBalance($this->customer)
            ->reduce(fn (string $carry, Sale $sale) => bcadd($carry, $sale->pending, 2), '0.00');
    }

    #[Computed]
    public function lastPayment(): ?CustomerPayment
    {
        return $this->customer->payments()->latest('paid_at')->first();
    }

    #[Computed]
    public function creditSales(): Collection
    {
        return app(CustomerBalanceService::class)->creditSales($this->customer);
    }

    #[Computed]
    public function ledger(): Collection
    {
        return app(CustomerBalanceService::class)->ledger(
            $this->customer,
            $this->customer->destinations->firstWhere('id', (int) $this->ledger_destination_id),
        );
    }

    /**
     * Sales with a balance in the account the payment goes to, oldest first.
     */
    #[Computed]
    public function openSales(): Collection
    {
        return app(CustomerBalanceService::class)->salesWithBalance(
            $this->customer,
            $this->customer->destinations->firstWhere('id', $this->customer_destination_id),
        );
    }

    /**
     * How the typed amount would be applied, the way CustomerPaymentService applies it.
     *
     * @return array{lines: list<array{folio: string, applied: string, settles: bool}>, remaining_balance: string, exceeds: bool}
     */
    #[Computed]
    public function preview(): array
    {
        $sales = $this->openSales;

        if ($this->start_sale_id !== '' && ($chosen = $sales->firstWhere('id', (int) $this->start_sale_id))) {
            $sales = $sales->reject(fn (Sale $sale) => $sale->is($chosen))->prepend($chosen);
        }

        $owed = $sales->reduce(fn (string $carry, Sale $sale) => bcadd($carry, $sale->pending, 2), '0.00');
        $left = is_numeric($this->amount) && $this->amount > 0 ? bcadd($this->amount, '0', 2) : '0.00';
        $lines = [];

        foreach ($sales as $sale) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }

            $applied = bccomp($sale->pending, $left, 2) < 0 ? $sale->pending : $left;
            $lines[] = ['folio' => $sale->folio, 'applied' => $applied, 'settles' => bccomp($applied, $sale->pending, 2) === 0];
            $left = bcsub($left, $applied, 2);
        }

        $paid = is_numeric($this->amount) ? bcadd($this->amount, '0', 2) : '0.00';

        return ['lines' => $lines, 'remaining_balance' => bcsub($owed, $paid, 2), 'exceeds' => bccomp($paid, $owed, 2) === 1];
    }

    #[Computed]
    public function registerIsOpen(): bool
    {
        return app(CashSessionService::class)->current() !== null;
    }

    public function updatedCustomerDestinationId(): void
    {
        $this->start_sale_id = '';
    }

    public function registerPayment(CustomerPaymentService $payments): void
    {
        $this->validate(['method' => ['required', 'in:cash,card,transfer']]);

        $payments->register(
            Auth::user(),
            $this->customer,
            $this->customer->destinations->firstWhere('id', $this->customer_destination_id),
            PaymentMethod::from($this->method),
            $this->amount,
            $this->reference ?: null,
            $this->note ?: null,
            $this->start_sale_id !== '' ? Sale::query()->findOrFail((int) $this->start_sale_id) : null,
        );

        $this->reset('amount', 'reference', 'note', 'start_sale_id');
        unset($this->balance, $this->accounts, $this->unassignedBalance, $this->lastPayment, $this->creditSales, $this->ledger, $this->openSales);

        Flux::toast(variant: 'success', text: __('Abono registrado.'));
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $limit = $customer->credit_limit !== null ? __('límite :limit', ['limit' => $money($customer->credit_limit)]) : __('crédito abierto, sin límite');
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('customers.index')" wire:navigate>{{ __('Clientes') }}</flux:button>
            <flux:heading size="xl" class="mt-2">{{ $customer->name }}</flux:heading>
            <flux:text class="mt-1">
                {{ collect([
                    $customer->hasCredit() ? __('Cliente de crédito · :limit', ['limit' => $limit]) : __('Cliente de contado'),
                    __('RFC :rfc', ['rfc' => $customer->rfc ?? __('[POR CAPTURAR]')]),
                ])->join(' · ') }}
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button icon="pencil" :href="route('customers.show', $customer)" wire:navigate>{{ __('Ficha') }}</flux:button>
            <flux:button icon="printer" :href="route('customers.statement.print', $customer)" target="_blank">{{ __('Imprimir estado de cuenta') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="flex flex-col gap-1 border-accent">
            <flux:text size="sm">{{ __('Saldo total') }}</flux:text>
            <flux:heading size="lg" class="tabular-nums">{{ $money($this->balance) }}</flux:heading>
            <flux:text size="sm">{{ $this->lastPayment ? __('Último abono: :date', ['date' => $this->lastPayment->paid_at->translatedFormat('j M')]) : __('Sin abonos todavía') }}</flux:text>
        </flux:card>
        @foreach ($this->accounts as $account)
            <flux:card wire:key="account-{{ $account['destination']->id }}" class="flex flex-col gap-1">
                <flux:text size="sm">{{ __('Cuenta :name', ['name' => $account['destination']->name]) }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">{{ $money($account['balance']) }}</flux:heading>
                <flux:text size="sm">{{ trans_choice(':count venta con saldo|:count ventas con saldo', $account['open_sales']) }}</flux:text>
            </flux:card>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <flux:card class="flex flex-col gap-3 p-0">
                <flux:heading class="px-6 pt-6">{{ __('Ventas') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Venta') }}</flux:table.column>
                        <flux:table.column>{{ __('Cuenta') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Abonado') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Pendiente') }}</flux:table.column>
                        <flux:table.column>{{ __('Estado') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->creditSales as $row)
                            @php
                                $status = match (true) {
                                    bccomp($row['pending'], '0', 2) <= 0 => ['label' => __('Liquidada').($row['settled_at'] ? ' '.$row['settled_at']->translatedFormat('j M') : ''), 'color' => 'green'],
                                    bccomp($row['paid'], '0', 2) === 1 => ['label' => __('Parcial'), 'color' => 'amber'],
                                    default => ['label' => __('Pendiente'), 'color' => 'red'],
                                };
                            @endphp
                            <flux:table.row :key="$row['sale']->id">
                                <flux:table.cell>{{ $row['sale']->folio }} · {{ $row['sale']->sold_at->translatedFormat('j M') }}</flux:table.cell>
                                <flux:table.cell>{{ $row['sale']->customerDestination?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $money($row['on_credit']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $money($row['paid']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-medium tabular-nums">{{ $money($row['pending']) }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6"><flux:text class="py-6 text-center">{{ __('Este cliente no tiene ventas a crédito.') }}</flux:text></flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card class="flex flex-col gap-3 p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-6">
                    <flux:heading>{{ __('Movimientos') }}</flux:heading>
                    @if ($customer->destinations->isNotEmpty())
                        <flux:radio.group wire:model.live="ledger_destination_id" variant="segmented" size="sm">
                            <flux:radio value="" :label="__('Todas')" />
                            @foreach ($customer->destinations as $destination)
                                <flux:radio :value="$destination->id" :label="$destination->name" />
                            @endforeach
                        </flux:radio.group>
                    @endif
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                        <flux:table.column>{{ __('Concepto') }}</flux:table.column>
                        <flux:table.column>{{ __('Cuenta') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cargo') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Abono') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Saldo cuenta') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->ledger->reverse() as $index => $entry)
                            <flux:table.row :key="'entry-'.$index">
                                <flux:table.cell class="whitespace-nowrap">{{ $entry['date']->translatedFormat('j M') }}</flux:table.cell>
                                <flux:table.cell>{{ $entry['concept'] }}</flux:table.cell>
                                <flux:table.cell>{{ $entry['account'] ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ (float) $entry['charge'] > 0 ? $money($entry['charge']) : '' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ (float) $entry['payment'] > 0 ? $money($entry['payment']) : '' }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-medium tabular-nums">{{ $money($entry['balance']) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6"><flux:text class="py-6 text-center">{{ __('Sin movimientos.') }}</flux:text></flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>

        @can(\App\Models\Permission::RegisterPayments->value)
            <flux:card class="flex h-fit flex-col gap-4">
                <flux:heading>{{ __('Registrar abono') }}</flux:heading>

                @unless ($this->registerIsOpen)
                    <flux:callout variant="warning" icon="lock-closed" :heading="__('La caja está cerrada')">
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('cash.index')" wire:navigate>{{ __('Abrir caja') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @endunless

                <form wire:submit="registerPayment" class="flex flex-col gap-4">
                    @if ($customer->destinations->isNotEmpty())
                        <flux:select wire:model.live="customer_destination_id" :label="__('Cuenta')">
                            @foreach ($this->accounts as $account)
                                <flux:select.option :value="$account['destination']->id">{{ $account['destination']->name }} · {{ $money($account['balance']) }}</flux:select.option>
                            @endforeach
                            @if (bccomp($this->unassignedBalance, '0', 2) === 1)
                                <flux:select.option value="">{{ __('Sin cuenta') }} · {{ $money($this->unassignedBalance) }}</flux:select.option>
                            @endif
                        </flux:select>
                    @endif

                    <flux:select wire:model.live="start_sale_id" :label="__('Aplicar a')" :description="__('Si sobra, pasa a la siguiente venta de la cuenta.')">
                        <flux:select.option value="">{{ __('La venta más antigua') }}</flux:select.option>
                        @foreach ($this->openSales as $sale)
                            <flux:select.option :value="$sale->id">{{ $sale->folio }} · {{ __('pendiente :amount', ['amount' => $money($sale->pending)]) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="grid grid-cols-2 gap-3">
                        <flux:input wire:model.live.debounce.300ms="amount" :label="__('Monto')" type="number" step="0.01" min="0" icon="currency-dollar" required />
                        <flux:select wire:model="method" :label="__('Forma de pago')">
                            <flux:select.option value="cash">{{ __('Efectivo') }}</flux:select.option>
                            <flux:select.option value="card">{{ __('Tarjeta') }}</flux:select.option>
                            <flux:select.option value="transfer">{{ __('Transferencia') }}</flux:select.option>
                        </flux:select>
                    </div>
                    <flux:input wire:model="reference" :label="__('Referencia')" />
                    <flux:input wire:model="note" :label="__('Nota')" />

                    @if ($this->preview['lines'] !== [])
                        <div class="flex flex-col gap-1 rounded-lg bg-surface p-3 text-sm dark:bg-zinc-800">
                            @foreach ($this->preview['lines'] as $line)
                                <span>{{ $line['settles'] ? __('Liquida :folio (:amount)', ['folio' => $line['folio'], 'amount' => $money($line['applied'])]) : __('Abona :amount a :folio', ['folio' => $line['folio'], 'amount' => $money($line['applied'])]) }}</span>
                            @endforeach
                            @unless ($this->preview['exceeds'])
                                <span class="font-medium">{{ __('Saldo queda en :amount', ['amount' => $money($this->preview['remaining_balance'])]) }}</span>
                            @endunless
                        </div>
                    @endif
                    @if ($this->preview['exceeds'])
                        <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ __('El abono es mayor a lo que debe la cuenta.') }}</flux:text>
                    @endif

                    @if ($errors->any())
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            @foreach ($errors->all() as $message)
                                <flux:callout.text>{{ $message }}</flux:callout.text>
                            @endforeach
                        </flux:callout>
                    @endif

                    <flux:button type="submit" variant="primary" :disabled="! $this->registerIsOpen" data-test="register-payment-button">{{ __('Guardar abono') }}</flux:button>
                </form>
            </flux:card>
        @endcan
    </div>
</div>

<?php

use App\Models\CashMovementCategory;
use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use App\Services\CashSessionService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Caja')] class extends Component {
    // Abrir caja
    public string $opening_cash = '';
    public string $opening_note = '';

    // Nueva salida de efectivo
    public string $amount = '';
    public string $category = 'expense';
    public ?int $supplier_id = null;
    public string $beneficiary = '';
    public string $note = '';

    // Corte
    public string $counted_cash = '';
    public string $cash_left = '';
    public string $closing_note = '';

    public function mount(): void
    {
        $this->opening_cash = $this->previousCashLeft ?? '';
    }

    #[Computed]
    public function openSession(): ?CashSession
    {
        return app(CashSessionService::class)->current()?->load('openedBy');
    }

    /**
     * What the last closing left in the register for today.
     */
    #[Computed]
    public function previousCashLeft(): ?string
    {
        return CashSession::query()
            ->where('status', CashSessionStatus::Closed)
            ->latest('closed_at')
            ->latest('id')
            ->value('cash_left');
    }

    /**
     * @return array{opening_cash: string, cash_sales: string, cash_customer_payments: string, inflows: string, outflows: string, cash_refunds: string, cash_supplier_payments: string, expected_cash: string}
     */
    #[Computed]
    public function breakdown(): array
    {
        return app(CashSessionService::class)->cashBreakdown($this->openSession);
    }

    /**
     * @return array{cash: string, card: string, transfer: string, credit: string, sold: string, sales_count: int}
     */
    #[Computed]
    public function salesSummary(): array
    {
        return app(CashSessionService::class)->salesSummary($this->openSession);
    }

    #[Computed]
    public function outflows(): Collection
    {
        return $this->openSession->cashMovements()
            ->with('supplier')
            ->where('type', CashMovementType::Outflow)
            ->orderBy('occurred_at')
            ->get();
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()
            ->where('is_active', true)
            ->where('settlement_type', '!=', SupplierSettlementType::InKind)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Counted minus expected, while the count is being typed.
     */
    #[Computed]
    public function difference(): ?string
    {
        if (! is_numeric($this->counted_cash)) {
            return null;
        }

        return bcsub(bcadd($this->counted_cash, '0', 2), $this->breakdown['expected_cash'], 2);
    }

    public function openRegister(CashSessionService $cashSessions): void
    {
        $cashSessions->open(Auth::user(), $this->opening_cash, $this->opening_note ?: null);

        $this->reset('opening_note');
        $this->refreshRegister();
        Flux::toast(variant: 'success', text: __('Caja abierta.'));
    }

    public function recordOutflow(CashSessionService $cashSessions): void
    {
        $category = CashMovementCategory::from($this->category);
        $supplier = $category === CashMovementCategory::SupplierExpense && $this->supplier_id
            ? Supplier::query()->findOrFail($this->supplier_id)
            : null;

        $cashSessions->recordMovement(
            Auth::user(),
            CashMovementType::Outflow,
            $category->label(),
            $this->amount,
            $this->beneficiary ?: null,
            note: $this->note ?: null,
            category: $category,
            supplier: $supplier,
        );

        $this->reset('amount', 'supplier_id', 'beneficiary', 'note');
        unset($this->outflows, $this->breakdown);
        Flux::toast(variant: 'success', text: __('Salida registrada.'));
    }

    public function closeRegister(CashSessionService $cashSessions): void
    {
        $cashSessions->close(Auth::user(), $this->counted_cash, $this->cash_left, $this->closing_note ?: null);

        $this->opening_cash = $this->cash_left;
        $this->reset('counted_cash', 'cash_left', 'closing_note');
        $this->refreshRegister();
        Flux::toast(variant: 'success', text: __('Caja cerrada.'));
    }

    private function refreshRegister(): void
    {
        unset($this->openSession, $this->previousCashLeft, $this->breakdown, $this->salesSummary, $this->outflows);
        $this->dispatch('cash-session-changed');
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    @if (! $this->openSession)
        {{-- docs/diseno/AbrirCaja.html --}}
        <flux:card class="mx-auto w-full max-w-md">
            <form wire:submit="openRegister" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="xl">{{ __('Abrir caja') }}</flux:heading>
                    <flux:text class="mt-1">{{ now()->translatedFormat('D j M Y') }} · {{ auth()->user()->name }}</flux:text>
                </div>

                <flux:text>{{ __('Antes de vender, cuenta el efectivo con el que empiezas el día.') }}</flux:text>

                <flux:field>
                    <flux:label>{{ __('Fondo inicial') }}</flux:label>
                    <flux:input wire:model.live="opening_cash" type="number" step="0.01" min="0" icon="currency-dollar" required />
                    @if ($this->previousCashLeft !== null)
                        <flux:description>{{ __('El último corte dejó :amount de fondo.', ['amount' => $money($this->previousCashLeft)]) }}</flux:description>
                    @endif
                    <flux:error name="opening_cash" />
                </flux:field>

                @if ($this->previousCashLeft !== null && is_numeric($opening_cash) && bccomp(bcadd($opening_cash, '0', 2), $this->previousCashLeft, 2) !== 0)
                    <flux:field>
                        <flux:label>{{ __('Nota') }}</flux:label>
                        <flux:textarea wire:model="opening_note" rows="2" :placeholder="__('¿Por qué no coincide con el fondo del último corte?')" />
                        <flux:error name="opening_note" />
                    </flux:field>
                @endif

                <flux:error name="cash_session" />

                <flux:button type="submit" variant="primary" class="w-full" data-test="open-register-button">
                    {{ __('Abrir caja y empezar a vender') }}
                </flux:button>
            </form>
        </flux:card>
    @else
        {{-- docs/diseno/Caja.html --}}
        <div>
            <flux:heading size="xl">{{ __('Caja de hoy') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Abierta a las :time por :user con fondo de :amount', [
                    'time' => $this->openSession->opened_at->format('H:i'),
                    'user' => $this->openSession->openedBy->name,
                    'amount' => $money($this->openSession->opening_cash),
                ]) }}
            </flux:text>
        </div>

        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach ([
                __('Efectivo') => $this->salesSummary['cash'],
                __('Tarjeta') => $this->salesSummary['card'],
                __('Transferencia') => $this->salesSummary['transfer'],
                __('A crédito') => $this->salesSummary['credit'],
            ] as $label => $value)
                <flux:card class="flex flex-col gap-1">
                    <flux:text size="sm">{{ $label }}</flux:text>
                    <flux:heading size="lg">{{ $money($value) }}</flux:heading>
                </flux:card>
            @endforeach
            <flux:card class="flex flex-col gap-1 border-accent">
                <flux:text size="sm">{{ __('Vendido hoy') }}</flux:text>
                <flux:heading size="lg">{{ $money($this->salesSummary['sold']) }}</flux:heading>
                <flux:text size="sm">{{ trans_choice(':count venta|:count ventas', $this->salesSummary['sales_count']) }}</flux:text>
            </flux:card>
        </div>

        <div class="grid gap-6 lg:grid-cols-5">
            <flux:card class="flex flex-col gap-4 lg:col-span-3">
                <flux:heading size="lg">{{ __('Salidas de efectivo') }}</flux:heading>

                @if ($this->outflows->isEmpty())
                    <flux:text>{{ __('Todavía no hay salidas de efectivo hoy.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Hora') }}</flux:table.column>
                            <flux:table.column>{{ __('Concepto') }}</flux:table.column>
                            <flux:table.column>{{ __('A quién') }}</flux:table.column>
                            <flux:table.column>{{ __('Nota') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Monto') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->outflows as $outflow)
                                <flux:table.row :key="$outflow->id">
                                    <flux:table.cell>{{ $outflow->occurred_at->format('H:i') }}</flux:table.cell>
                                    <flux:table.cell>{{ $outflow->concept }}</flux:table.cell>
                                    <flux:table.cell>{{ $outflow->beneficiary }}</flux:table.cell>
                                    <flux:table.cell class="max-w-56 truncate">{{ $outflow->note }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $money($outflow->amount) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif

                <flux:separator />

                <form wire:submit="recordOutflow" class="grid gap-4 sm:grid-cols-2">
                    <flux:heading class="sm:col-span-2">{{ __('Nueva salida de efectivo') }}</flux:heading>

                    <flux:input wire:model="amount" :label="__('Monto')" type="number" step="0.01" min="0" icon="currency-dollar" required />

                    <flux:select wire:model.live="category" :label="__('Concepto')">
                        @foreach ([\App\Models\CashMovementCategory::Expense, \App\Models\CashMovementCategory::SupplierExpense, \App\Models\CashMovementCategory::OwnerWithdrawal, \App\Models\CashMovementCategory::Other] as $option)
                            <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($category === 'supplier_expense')
                        <flux:select wire:model="supplier_id" :label="__('Proveedor')" :placeholder="__('Elige…')" class="sm:col-span-2">
                            @foreach ($this->suppliers as $supplier)
                                <flux:select.option :value="$supplier->id">{{ $supplier->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:text size="sm" class="sm:col-span-2">{{ __('Fletes y gastos con el proveedor. Para abonar a lo que se le debe usa Proveedores.') }}</flux:text>
                    @endif

                    <flux:input wire:model="beneficiary" :label="__('A quién')" :placeholder="$category === 'supplier_expense' ? __('El proveedor, si se deja vacío') : ''" />
                    <flux:input wire:model="note" :label="__('Nota')" />

                    <flux:error name="supplier_id" class="sm:col-span-2" />

                    <div class="sm:col-span-2">
                        <flux:button type="submit" data-test="record-outflow-button">{{ __('Registrar salida') }}</flux:button>
                    </div>
                </form>
            </flux:card>

            <flux:card class="flex flex-col gap-4 lg:col-span-2">
                <flux:heading size="lg">{{ __('Corte de caja') }}</flux:heading>

                @php
                    $lines = [
                        [__('Fondo inicial'), '', $this->breakdown['opening_cash'], true],
                        [__('Ventas en efectivo'), '+', $this->breakdown['cash_sales'], true],
                        [__('Abonos en efectivo'), '+', $this->breakdown['cash_customer_payments'], true],
                        [__('Entradas'), '+', $this->breakdown['inflows'], false],
                        [__('Salidas'), '−', $this->breakdown['outflows'], true],
                        [__('Devoluciones en efectivo'), '−', $this->breakdown['cash_refunds'], false],
                        [__('Pagos a proveedores'), '−', $this->breakdown['cash_supplier_payments'], false],
                    ];
                @endphp
                <dl class="flex flex-col gap-2 text-sm">
                    {{-- Optional lines only appear when they have an amount. --}}
                    @foreach ($lines as [$label, $sign, $value, $alwaysShown])
                        @if ($alwaysShown || bccomp($value, '0', 2) !== 0)
                            <div class="flex justify-between">
                                <dt>{{ $label }}</dt>
                                <dd class="tabular-nums">{{ $sign }}{{ $money($value) }}</dd>
                            </div>
                        @endif
                    @endforeach
                    <div class="flex justify-between border-t border-line pt-2 font-semibold dark:border-zinc-700">
                        <dt>{{ __('Debe haber') }}</dt>
                        <dd class="tabular-nums" data-test="expected-cash">{{ $money($this->breakdown['expected_cash']) }}</dd>
                    </div>
                </dl>

                <form wire:submit="closeRegister" class="flex flex-col gap-4">
                    <flux:input wire:model.live.debounce.300ms="counted_cash" :label="__('Contado en caja')" type="number" step="0.01" min="0" icon="currency-dollar" required />

                    @if ($this->difference !== null)
                        <flux:text class="flex justify-between font-medium {{ bccomp($this->difference, '0', 2) === 0 ? '' : 'text-red-700 dark:text-red-400' }}">
                            <span>{{ __('Diferencia') }}</span>
                            <span class="tabular-nums">{{ bccomp($this->difference, '0', 2) < 0 ? '−'.$money(ltrim($this->difference, '-')) : $money($this->difference) }}</span>
                        </flux:text>
                    @endif

                    <flux:input wire:model="cash_left" :label="__('Fondo que se deja para mañana')" type="number" step="0.01" min="0" icon="currency-dollar" required />
                    <flux:textarea wire:model="closing_note" :label="__('Nota del corte')" rows="2" />

                    <flux:error name="cash_session" />

                    <flux:button type="submit" variant="primary" wire:confirm="{{ __('¿Cerrar la caja de hoy?') }}" data-test="close-register-button">
                        {{ __('Cerrar caja') }}
                    </flux:button>
                    <flux:text size="sm">{{ __('Tarjeta, transferencia y crédito no se cuentan en efectivo.') }}</flux:text>
                </form>
            </flux:card>
        </div>
    @endif
</div>

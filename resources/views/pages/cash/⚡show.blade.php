<?php

use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Services\CashSessionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Corte de caja')] class extends Component {
    public CashSession $cashSession;

    public function mount(CashSession $cashSession): void
    {
        $this->cashSession = $cashSession->load(['openedBy', 'closedBy']);
    }

    /**
     * @return array{breakdown: array<string, string>, sales: array<string, string|int>, movements: \Illuminate\Support\Collection<int, \App\Models\CashMovement>}
     */
    #[Computed]
    public function report(): array
    {
        return app(CashSessionService::class)->report($this->cashSession);
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $session = $cashSession;
    $report = $this->report;
    $breakdown = $report['breakdown'];
    $difference = (string) ($session->cash_difference ?? '0');
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:link :href="route('cash.history')" wire:navigate class="text-sm">← {{ __('Cortes de caja') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl">{{ __('Corte del :date', ['date' => $session->opened_at->translatedFormat('l j \d\e F')]) }}</flux:heading>
                    @if ($session->isOpen())
                        <flux:badge size="sm" color="green">{{ __('Abierta') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1">
                    {{ __('Abrió :user a las :time con :amount', ['user' => $session->openedBy->name, 'time' => $session->opened_at->format('H:i'), 'amount' => $money($session->opening_cash)]) }}
                    @if ($session->closed_at)
                        · {{ __('cerró :user a las :time', ['user' => $session->closedBy?->name, 'time' => $session->closed_at->format('H:i')]) }}
                    @endif
                </flux:text>
            </div>
            @unless ($session->isOpen())
                <flux:button icon="printer" :href="route('cash.print', $session)" target="_blank">{{ __('Imprimir corte') }}</flux:button>
            @else
                <flux:button :href="route('cash.index')" wire:navigate>{{ __('Ir a la caja de hoy') }}</flux:button>
            @endunless
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
        @foreach ([__('Efectivo') => $report['sales']['cash'], __('Tarjeta') => $report['sales']['card'], __('Transferencia') => $report['sales']['transfer'], __('A crédito') => $report['sales']['credit']] as $label => $value)
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm">{{ $label }}</flux:text>
                <flux:heading size="lg">{{ $money($value) }}</flux:heading>
            </flux:card>
        @endforeach
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Vendido') }}</flux:text>
            <flux:heading size="lg">{{ $money($report['sales']['sold']) }}</flux:heading>
            <flux:text size="sm">{{ trans_choice(':count venta|:count ventas', $report['sales']['sales_count']) }}</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <flux:card class="flex flex-col gap-3 lg:col-span-3">
            <flux:heading>{{ __('Entradas y salidas de efectivo') }}</flux:heading>
            @if ($report['movements']->isEmpty())
                <flux:text>{{ __('No hubo entradas ni salidas a mano.') }}</flux:text>
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
                        @foreach ($report['movements'] as $movement)
                            <flux:table.row :key="$movement->id">
                                <flux:table.cell>{{ $movement->occurred_at->format('H:i') }}</flux:table.cell>
                                <flux:table.cell>{{ $movement->concept }}</flux:table.cell>
                                <flux:table.cell>{{ $movement->beneficiary }}</flux:table.cell>
                                <flux:table.cell class="max-w-56 truncate">{{ $movement->note }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $movement->type === CashMovementType::Outflow ? '−' : '+' }}{{ $money($movement->amount) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-3 lg:col-span-2">
            <flux:heading>{{ __('Corte de caja') }}</flux:heading>
            <dl class="flex flex-col gap-1 text-sm tabular-nums">
                @foreach ([
                    [__('Fondo inicial'), '', $breakdown['opening_cash']],
                    [__('Ventas en efectivo'), '+', $breakdown['cash_sales']],
                    [__('Abonos en efectivo'), '+', $breakdown['cash_customer_payments']],
                    [__('Entradas'), '+', $breakdown['inflows']],
                    [__('Salidas'), '−', $breakdown['outflows']],
                    [__('Devoluciones en efectivo'), '−', $breakdown['cash_refunds']],
                    [__('Pagos a proveedores'), '−', $breakdown['cash_supplier_payments']],
                ] as [$label, $sign, $value])
                    <div class="flex justify-between"><dt>{{ $label }}</dt><dd>{{ $sign }}{{ $money($value) }}</dd></div>
                @endforeach
                <div class="flex justify-between border-t border-line pt-2 font-semibold dark:border-zinc-700"><dt>{{ __('Debía haber') }}</dt><dd>{{ $money($session->isOpen() ? $breakdown['expected_cash'] : $session->expected_cash) }}</dd></div>
                @unless ($session->isOpen())
                    <div class="flex justify-between"><dt>{{ __('Contado') }}</dt><dd>{{ $money($session->counted_cash) }}</dd></div>
                    <div class="flex justify-between font-semibold {{ bccomp($difference, '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                        <dt>{{ __('Diferencia') }}</dt>
                        <dd>{{ bccomp($difference, '0', 2) === 0 ? __('Cuadra') : (bccomp($difference, '0', 2) > 0 ? __('Sobran :amount', ['amount' => $money($difference)]) : __('Faltan :amount', ['amount' => $money(abs((float) $difference))])) }}</dd>
                    </div>
                    <div class="flex justify-between"><dt>{{ __('Fondo que se dejó') }}</dt><dd>{{ $money($session->cash_left) }}</dd></div>
                @endunless
            </dl>
            @if ($session->closing_note)
                <flux:text size="sm">{{ __('Nota: :note', ['note' => $session->closing_note]) }}</flux:text>
            @endif
            @if ($session->opening_note)
                <flux:text size="sm">{{ __('Nota al abrir: :note', ['note' => $session->opening_note]) }}</flux:text>
            @endif
        </flux:card>
    </div>
</div>

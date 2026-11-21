<?php

use App\Models\Permission;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Reportes')] class extends Component {
    /** 'today', 'week', 'month' or 'custom'. */
    #[Url(as: 'periodo', except: 'month')]
    public string $period = 'month';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $until = '';

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    #[Computed]
    public function range(): array
    {
        $today = CarbonImmutable::today();

        return match ($this->period) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(), $today],
            'custom' => [
                rescue(fn () => \Carbon\CarbonImmutable::parse($this->from), $today->startOfMonth(), false),
                rescue(fn () => \Carbon\CarbonImmutable::parse($this->until), $today, false),
            ],
            default => [$today->startOfMonth(), $today],
        };
    }

    #[Computed]
    public function lines(): Collection
    {
        [$from, $until] = $this->range;

        return app(ReportService::class)->lines($from, $until->max($from));
    }

    /**
     * @return array{sales: string, net: string, profit: string, margin: string|null, days_with_sales: int, missing_cost: bool}
     */
    #[Computed]
    public function summary(): array
    {
        return app(ReportService::class)->summary($this->lines);
    }

    #[Computed]
    public function salesByDay(): Collection
    {
        [$from, $until] = $this->range;

        return app(ReportService::class)->salesByDay($this->lines, $from, $until->max($from));
    }

    #[Computed]
    public function receivables(): Collection
    {
        return app(ReportService::class)->receivables();
    }

    #[Computed]
    public function payables(): Collection
    {
        return app(ReportService::class)->payables();
    }

    public function updatedPeriod(): void
    {
        if ($this->period === 'custom' && $this->from === '') {
            [$from, $until] = $this->range;
            $this->from = $from->toDateString();
            $this->until = $until->toDateString();
        }
    }

    /**
     * Every line sold in the period as a CSV that Excel opens directly.
     */
    public function export(): StreamedResponse
    {
        Gate::authorize(Permission::ViewReports->value);

        [$from, $until] = $this->range;
        $lines = $this->lines;

        return response()->streamDownload(function () use ($lines): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [__('Fecha'), __('Folio'), __('Cliente'), __('Destino'), __('Producto'), __('Dueño'), __('Piezas'), __('Venta'), __('Venta sin impuestos'), __('Costo'), __('Utilidad')]);

            foreach ($lines as $line) {
                fputcsv($output, [
                    $line['sold_at']->format('Y-m-d H:i'),
                    $line['folio'],
                    $line['customer'] ?? __('Público en general'),
                    $line['destination'] ?? '',
                    $line['item']->product->name,
                    $line['owner'],
                    $line['quantity'],
                    $line['sales'],
                    $line['net'],
                    $line['missing_cost'] ? '' : $line['cost'],
                    $line['missing_cost'] ? '' : $line['profit'],
                ]);
            }

            fclose($output);
        }, 'ventas-'.$from->toDateString().'-a-'.$until->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $reports = app(\App\Services\ReportService::class);
    [$from, $until] = $this->range;
    $summary = $this->summary;
    $byDay = $this->salesByDay;
    $maxDay = max(1.0, (float) $byDay->max(fn ($amount) => (float) $amount));
    $peakDay = $byDay->filter(fn ($amount) => (float) $amount > 0)->sortByDesc(fn ($amount) => (float) $amount)->keys()->first();
    $byDestination = $reports->salesByDestination($this->lines);
    $products = $reports->products($this->lines)->take(10);
    $inventory = $reports->inventoryAtCost();
    $receivableTotal = $this->receivables->reduce(fn ($carry, $row) => bcadd($carry, $row['balance'], 2), '0.00');
    $payableTotal = $this->payables->reduce(fn ($carry, $row) => bcadd($carry, $row['pending'], 2), '0.00');
    $topPayable = $this->payables->first();
    $belowMinimum = $reports->belowMinimumCount();
    $cashOpen = app(\App\Services\CashSessionService::class)->current() !== null;
    $periodLabel = $from->isSameDay($until)
        ? $from->translatedFormat('j \d\e F \d\e Y')
        : __('Del :from al :until', ['from' => $from->translatedFormat($from->isSameMonth($until) ? 'j' : 'j \d\e F'), 'until' => $until->translatedFormat('j \d\e F \d\e Y')]);
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Reportes') }}</flux:heading>
            <flux:text class="mt-1">{{ $periodLabel }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <flux:radio.group wire:model.live="period" variant="segmented" size="sm">
                <flux:radio value="today" :label="__('Hoy')" />
                <flux:radio value="week" :label="__('Semana')" />
                <flux:radio value="month" :label="__('Mes')" />
                <flux:radio value="custom" :label="__('Elegir fechas')" />
            </flux:radio.group>
            <flux:button size="sm" icon="arrow-down-tray" wire:click="export" data-test="export-button">{{ __('Exportar a Excel') }}</flux:button>
        </div>
    </div>

    @if ($period === 'custom')
        <div class="flex flex-wrap gap-3">
            <flux:input wire:model.live="from" type="date" :label="__('Desde')" />
            <flux:input wire:model.live="until" type="date" :label="__('Hasta')" />
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Ventas') }}</flux:text>
            <flux:heading size="lg">{{ $money($summary['sales']) }}</flux:heading>
            <flux:text size="sm">{{ trans_choice(':count día con venta|:count días con venta', $summary['days_with_sales']) }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Utilidad bruta') }}</flux:text>
            <flux:heading size="lg">{{ $money($summary['profit']) }}</flux:heading>
            <flux:text size="sm">{{ $summary['margin'] !== null ? __('Margen :margin% · venta menos costo del lote', ['margin' => $summary['margin']]) : __('Venta menos costo del lote') }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Por cobrar a clientes') }}</flux:text>
            <flux:heading size="lg">{{ $money($receivableTotal) }}</flux:heading>
            <flux:text size="sm">{{ trans_choice(':count cliente|:count clientes', $this->receivables->count()) }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Por pagar a proveedores') }}</flux:text>
            <flux:heading size="lg">{{ $money($payableTotal) }}</flux:heading>
            <flux:text size="sm">
                @if ($topPayable)
                    {{ $this->payables->count() > 1
                        ? __(':supplier :amount y :count más', ['supplier' => $topPayable['supplier']->name, 'amount' => $money($topPayable['pending']), 'count' => $this->payables->count() - 1])
                        : __(':supplier :amount', ['supplier' => $topPayable['supplier']->name, 'amount' => $money($topPayable['pending'])]) }}
                @else
                    {{ __('No se debe nada') }}
                @endif
            </flux:text>
        </flux:card>
    </div>

    @if ($summary['missing_cost'])
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Hay ventas de lotes sin costo')">
            <flux:callout.text>{{ __('Su utilidad se cuenta como si el costo fuera cero. Captura el costo de referencia del producto para que salga bien.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Ventas por día: one series, one hue (brand); a tooltip per bar and a table for screen readers. --}}
        <flux:card class="flex flex-col gap-4 lg:col-span-2">
            <div>
                <flux:heading>{{ __('Ventas por día') }}</flux:heading>
                @if ($peakDay)
                    <flux:text size="sm">{{ __('El día más alto fue el :day', ['day' => \Carbon\CarbonImmutable::parse($peakDay)->translatedFormat('D j M')]) }}</flux:text>
                @endif
            </div>
            <div class="relative h-48" aria-hidden="true">
                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                    @foreach ([1, 0.5, 0] as $fraction)
                        <div class="flex items-center gap-2">
                            <span class="w-14 text-end text-xs tabular-nums text-zinc-500">{{ '$'.number_format($maxDay * $fraction / 1000, $maxDay < 10000 ? 1 : 0).'k' }}</span>
                            <div class="h-px flex-1 bg-line dark:bg-zinc-700"></div>
                        </div>
                    @endforeach
                </div>
                <div class="absolute inset-y-0 end-0 start-16 flex items-end gap-0.5">
                    @foreach ($byDay as $day => $amount)
                        @php
                            $height = (float) $amount / $maxDay * 100;
                            $date = \Carbon\CarbonImmutable::parse($day);
                        @endphp
                        <div class="group relative flex h-full flex-1 items-end justify-center" wire:key="day-{{ $day }}">
                            @if ($day === $peakDay)
                                <span class="absolute whitespace-nowrap text-xs font-medium tabular-nums" style="bottom: calc({{ $height }}% + 4px)">{{ $money($amount) }}</span>
                            @endif
                            <div class="w-full max-w-6 rounded-t bg-brand transition-opacity group-hover:opacity-80 dark:bg-emerald-500" style="height: {{ max($height, (float) $amount > 0 ? 1 : 0) }}%"></div>
                            <div class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded bg-zinc-900 px-2 py-1 text-xs text-white shadow group-hover:block dark:bg-zinc-100 dark:text-zinc-900">
                                {{ $date->translatedFormat('D j M') }} · {{ $money($amount) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @if ($byDay->count() <= 16)
                <div class="ms-16 flex gap-0.5" aria-hidden="true">
                    @foreach ($byDay as $day => $amount)
                        <span class="flex-1 text-center text-xs text-zinc-500">{{ \Carbon\CarbonImmutable::parse($day)->translatedFormat('j') }}</span>
                    @endforeach
                </div>
            @endif
            <table class="sr-only">
                <caption>{{ __('Ventas por día') }}</caption>
                @foreach ($byDay as $day => $amount)
                    <tr><th>{{ \Carbon\CarbonImmutable::parse($day)->translatedFormat('D j M') }}</th><td>{{ $money($amount) }}</td></tr>
                @endforeach
            </table>
        </flux:card>

        <flux:card class="flex flex-col gap-3">
            <div>
                <flux:heading>{{ __('Ventas por destino') }}</flux:heading>
                <flux:text size="sm">{{ __('Responde a "¿qué se llevó a tal lugar?"') }}</flux:text>
            </div>
            <ul class="flex flex-col divide-y divide-line dark:divide-zinc-700">
                @forelse ($byDestination as $row)
                    <li class="flex justify-between gap-3 py-2">
                        <span>{{ $row['destination'] ?? __('Sin destino') }}</span>
                        <span class="tabular-nums">{{ $money($row['sales']) }}</span>
                    </li>
                @empty
                    <li class="py-2"><flux:text>{{ __('Sin ventas en el periodo.') }}</flux:text></li>
                @endforelse
            </ul>
            @if ($byDestination->isNotEmpty())
                <div class="flex justify-between gap-3 font-semibold">
                    <span>{{ __('Total') }}</span>
                    <span class="tabular-nums">{{ $money($summary['sales']) }}</span>
                </div>
            @endif
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="flex flex-col gap-3 p-0 lg:col-span-2">
            <flux:heading class="px-4 pt-4">{{ __('Más vendidos y su utilidad') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Producto') }}</flux:table.column>
                    <flux:table.column>{{ __('Dueño') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Piezas') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Venta') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Utilidad') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Margen') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($products as $row)
                        <flux:table.row :key="$row['product']->id">
                            <flux:table.cell class="font-medium">{{ $row['product']->name }}</flux:table.cell>
                            <flux:table.cell>{{ $row['owner'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ rtrim(rtrim($row['quantity'], '0'), '.') ?: '0' }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $money($row['sales']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['missing_cost'] ? __('Sin costo') : $money($row['profit']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ ! $row['missing_cost'] && $row['margin'] !== null ? $row['margin'].'%' : '—' }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6"><flux:text class="py-4 text-center">{{ __('Sin ventas en el periodo.') }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <div class="flex flex-col gap-6">
            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Inventario a costo') }}</flux:heading>
                <ul class="flex flex-col divide-y divide-line dark:divide-zinc-700">
                    @forelse ($inventory as $row)
                        <li class="flex justify-between gap-3 py-2">
                            <span>{{ $row['own'] ? __('Propio') : __('De :owner', ['owner' => $row['owner']]) }}</span>
                            <span class="tabular-nums">{{ $money($row['value']) }}</span>
                        </li>
                    @empty
                        <li class="py-2"><flux:text>{{ __('Sin existencias.') }}</flux:text></li>
                    @endforelse
                </ul>
                <flux:text size="sm">{{ __('Solo lo propio es dinero invertido; lo de consigna se paga al venderse.') }}</flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Quién debe más') }}</flux:heading>
                <ul class="flex flex-col divide-y divide-line dark:divide-zinc-700">
                    @forelse ($this->receivables->take(5) as $row)
                        <li class="flex justify-between gap-3 py-2">
                            <flux:link :href="route('customers.statement', $row['customer'])" wire:navigate>{{ $row['customer']->name }}</flux:link>
                            <span class="tabular-nums">{{ $money($row['balance']) }}</span>
                        </li>
                    @empty
                        <li class="py-2"><flux:text>{{ __('Nadie debe.') }}</flux:text></li>
                    @endforelse
                </ul>
            </flux:card>

            <flux:card class="flex flex-col gap-2">
                <flux:heading>{{ __('Avisos') }}</flux:heading>
                <div class="flex justify-between gap-3 text-sm">
                    <span>{{ __('Bajo mínimo') }}</span>
                    <span>{{ trans_choice(':count producto|:count productos', $belowMinimum) }}</span>
                </div>
                <div class="flex justify-between gap-3 text-sm">
                    <span>{{ __('Caja de hoy') }}</span>
                    <flux:badge size="sm" :color="$cashOpen ? 'green' : 'zinc'">{{ $cashOpen ? __('Abierta') : __('Cerrada') }}</flux:badge>
                </div>
            </flux:card>
        </div>
    </div>
</div>

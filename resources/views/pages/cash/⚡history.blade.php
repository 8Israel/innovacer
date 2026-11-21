<?php

use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\SaleStatus;
use App\Services\CashSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cortes de caja')] class extends Component {
    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $until = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->toDateString();
    }

    /**
     * Sessions opened in the dates, newest first. An open one shows what it
     * should hold so far instead of its closing.
     *
     * @return Collection<int, CashSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        $from = rescue(fn () => Carbon::parse($this->from)->startOfDay(), now()->startOfMonth(), false);
        $until = rescue(fn () => Carbon::parse($this->until)->endOfDay(), now()->endOfDay(), false);

        return CashSession::query()
            ->with(['openedBy', 'closedBy'])
            ->withSum(['sales as sold_total' => fn ($sales) => $sales->where('status', SaleStatus::Completed)], 'total')
            ->whereBetween('opened_at', [$from, $until])
            ->latest('opened_at')
            ->latest('id')
            ->get()
            ->each(function (CashSession $session): void {
                if ($session->isOpen()) {
                    $session->expected_cash = app(CashSessionService::class)->expectedCash($session);
                }
            });
    }

    /**
     * @return array{closed: int, with_difference: int, difference: string}
     */
    #[Computed]
    public function summary(): array
    {
        $closed = $this->sessions->where('status', CashSessionStatus::Closed);
        $withDifference = $closed->filter(fn (CashSession $session) => bccomp((string) $session->cash_difference, '0', 2) !== 0);

        return [
            'closed' => $closed->count(),
            'with_difference' => $withDifference->count(),
            'difference' => $closed->reduce(fn (string $carry, CashSession $session) => bcadd($carry, (string) ($session->cash_difference ?? '0'), 2), '0.00'),
        ];
    }

    /**
     * "Cuadra", "Sobran $20" or "Faltan $100".
     *
     * @return array{label: string, color: string}
     */
    public function differenceOf(CashSession $session): array
    {
        $difference = (string) ($session->cash_difference ?? '0');
        $amount = '$'.number_format(abs((float) $difference), 2);

        return match (bccomp($difference, '0', 2)) {
            0 => ['label' => __('Cuadra'), 'color' => 'green'],
            1 => ['label' => __('Sobran :amount', ['amount' => $amount]), 'color' => 'amber'],
            default => ['label' => __('Faltan :amount', ['amount' => $amount]), 'color' => 'red'],
        };
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $summary = $this->summary;
    $signed = fn ($value) => (bccomp($value, '0', 2) < 0 ? '−' : (bccomp($value, '0', 2) > 0 ? '+' : '')).'$'.number_format(abs((float) $value), 2);
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div>
        <flux:link :href="route('cash.index')" wire:navigate class="text-sm">← {{ __('Caja de hoy') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
            <flux:heading size="xl">{{ __('Cortes de caja') }}</flux:heading>
            <div class="flex flex-wrap gap-3">
                <flux:input wire:model.live="from" type="date" :label="__('Desde')" />
                <flux:input wire:model.live="until" type="date" :label="__('Hasta')" />
            </div>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Cortes en las fechas') }}</flux:text>
            <flux:heading size="lg">{{ $summary['closed'] }}</flux:heading>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Cortes con diferencia') }}</flux:text>
            <flux:heading size="lg">{{ $summary['with_difference'] }}</flux:heading>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Diferencia acumulada') }}</flux:text>
            <flux:heading size="lg" class="{{ bccomp($summary['difference'], '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : '' }}">{{ $signed($summary['difference']) }}</flux:heading>
        </flux:card>
    </div>

    <flux:card class="p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Día') }}</flux:table.column>
                <flux:table.column>{{ __('Abrió · cerró') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Vendido') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Debía haber') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Contado') }}</flux:table.column>
                <flux:table.column>{{ __('Diferencia') }}</flux:table.column>
                <flux:table.column>{{ __('Nota') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->sessions as $session)
                    <flux:table.row :key="$session->id">
                        <flux:table.cell class="whitespace-nowrap font-medium">{{ ucfirst(str_replace('.', '', $session->opened_at->translatedFormat('D j M'))) }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $session->openedBy->name }}
                            @if ($session->isOpen())
                                · <flux:badge size="sm" color="green">{{ __('Abierta') }}</flux:badge>
                            @elseif ($session->closedBy && ! $session->closedBy->is($session->openedBy))
                                · {{ $session->closedBy->name }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $money($session->sold_total ?? 0) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $money($session->expected_cash) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $session->isOpen() ? '—' : $money($session->counted_cash) }}</flux:table.cell>
                        <flux:table.cell>
                            @unless ($session->isOpen())
                                @php
                                    $difference = $this->differenceOf($session);
                                @endphp
                                <flux:badge size="sm" :color="$difference['color']">{{ $difference['label'] }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="max-w-56 truncate">{{ $session->closing_note }}</flux:table.cell>
                        <flux:table.cell align="end" class="whitespace-nowrap">
                            <flux:link :href="route('cash.show', $session)" wire:navigate class="text-sm">{{ __('Ver') }}</flux:link>
                            @unless ($session->isOpen())
                                · <flux:link :href="route('cash.print', $session)" target="_blank" class="text-sm">{{ __('Imprimir') }}</flux:link>
                            @endunless
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8"><flux:text class="py-6 text-center">{{ __('No hay cortes en esas fechas.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>

<?php

use App\Concerns\RoundsDecimals;
use App\Models\CustomerReturnItem;
use App\Models\InvoiceStatus;
use App\Models\Permission;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CustomerBalanceService;
use App\Services\CustomerReturnService;
use App\Services\SaleAuthorization;
use App\Services\SaleService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Venta')] class extends Component {
    use RoundsDecimals;

    public Sale $sale;

    /** @var array<int, string> quantity to return by sale item id. */
    public array $return_quantities = [];

    public string $return_reason = '';

    /** 'restock' puts it back in the warehouse; 'waste' leaves it out (damaged). */
    public string $return_destination = 'restock';

    public string $refund_method = 'cash';

    public ?int $owner_id = null;

    public string $owner_password = '';

    public string $cancel_reason = '';

    public function mount(Sale $sale): void
    {
        abort_if($sale->isPending(), 404);

        $this->sale = $sale;
        $this->owner_id = $this->owners->first()?->id;
    }

    /**
     * @return Collection<int, SaleItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return $this->sale->items()
            ->with(['product', 'inventoryMovements' => fn ($movements) => $movements->where('quantity', '<', 0)->with('lot.supplier')])
            ->get();
    }

    /**
     * Units of each item the customer can still bring back.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function returnable(): array
    {
        $returned = CustomerReturnItem::query()
            ->whereIn('sale_item_id', $this->items->pluck('id'))
            ->groupBy('sale_item_id')
            ->selectRaw('sale_item_id, SUM(quantity) as returned')
            ->pluck('returned', 'sale_item_id');

        return $this->items->mapWithKeys(fn (SaleItem $item) => [
            $item->id => bcsub($item->quantity, $this->decimal($returned[$item->id] ?? '0', 3), 3),
        ])->all();
    }

    #[Computed]
    public function pending(): string
    {
        return app(CustomerBalanceService::class)->pending($this->sale);
    }

    /**
     * What the customer gets back for the marked quantities, the same way the return service computes it.
     */
    #[Computed]
    public function refundPreview(): string
    {
        return $this->items->reduce(function (string $carry, SaleItem $item): string {
            $quantity = $this->return_quantities[$item->id] ?? '';

            if (! is_numeric($quantity) || $quantity <= 0) {
                return $carry;
            }

            return bcadd($carry, $this->roundHalfUp(bcdiv(bcmul($item->total, (string) $quantity, 8), $item->quantity, 8), 2), 2);
        }, '0');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()->where('role', UserRole::Owner)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function isInvoiced(): bool
    {
        return $this->sale->invoices()->where('status', InvoiceStatus::Stamped)->exists();
    }

    /**
     * What happened to the sale, oldest first.
     *
     * @return Collection<int, array{at: \Illuminate\Support\Carbon, text: string}>
     */
    #[Computed]
    public function history(): Collection
    {
        $sale = $this->sale->loadMissing(['user', 'payments', 'paymentAllocations.customerPayment', 'customerReturns.user', 'canceledBy', 'authorizedBy']);
        $pieces = $this->format(array_reduce($this->items->pluck('quantity')->all(), fn (string $carry, string $quantity) => bcadd($carry, $quantity, 3), '0'));

        $events = collect([[
            'at' => $sale->sold_at,
            'text' => __('Venta registrada por :user; salieron :pieces piezas de :warehouse', ['user' => $sale->user->name, 'pieces' => $pieces, 'warehouse' => $sale->warehouse->name]),
        ]]);

        if ($sale->authorizedBy) {
            $events->push(['at' => $sale->sold_at, 'text' => __('Autorizó :user: :reason', ['user' => $sale->authorizedBy->name, 'reason' => $sale->authorization_reason])]);
        }

        foreach ($sale->payments as $payment) {
            $events->push(['at' => $payment->created_at, 'text' => __('Cobro en :method por :amount', ['method' => mb_strtolower($payment->method->label()), 'amount' => '$'.number_format((float) $payment->amount, 2)])]);
        }

        foreach ($sale->paymentAllocations as $allocation) {
            $events->push(['at' => $allocation->customerPayment->paid_at, 'text' => __('Abono en :method aplicado: :amount', ['method' => mb_strtolower($allocation->customerPayment->method->label()), 'amount' => '$'.number_format((float) $allocation->amount, 2)])]);
        }

        foreach ($sale->customerReturns as $return) {
            $events->push(['at' => $return->returned_at, 'text' => __('Devolución :folio por :amount (:refund), registrada por :user', [
                'folio' => $return->folio,
                'amount' => '$'.number_format((float) $return->refund_amount, 2),
                'refund' => $return->refund_method === RefundMethod::AccountCredit ? __('abono a su cuenta') : mb_strtolower(__('Efectivo')),
                'user' => $return->user->name,
            ])]);
        }

        if ($sale->isCanceled()) {
            $events->push(['at' => $sale->canceled_at, 'text' => __('Cancelada; autorizó :user: :reason', ['user' => $sale->canceledBy?->name, 'reason' => $sale->cancel_reason])]);
        }

        return $events->sortBy('at')->values();
    }

    public function registerReturn(CustomerReturnService $returns): void
    {
        Gate::authorize(Permission::RegisterReturns->value);

        $this->validate([
            'return_destination' => ['required', 'in:restock,waste'],
            'refund_method' => ['required', 'in:cash,account_credit'],
            'return_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $items = collect($this->return_quantities)
            ->filter(fn ($quantity) => is_numeric($quantity) && $quantity > 0)
            ->map(fn ($quantity, $saleItemId) => ['sale_item_id' => (int) $saleItemId, 'quantity' => (string) $quantity])
            ->values()
            ->all();

        $customerReturn = $returns->register(
            auth()->user(),
            $this->sale,
            $items,
            RefundMethod::from($this->refund_method),
            $this->return_destination === 'restock',
            $this->return_reason ?: null,
        );

        $this->reset('return_quantities', 'return_reason', 'return_destination', 'refund_method');
        $this->refreshSale();

        Flux::toast(variant: 'success', text: __('Devolución :folio registrada por $:amount.', ['folio' => $customerReturn->folio, 'amount' => number_format((float) $customerReturn->refund_amount, 2)]));
    }

    public function cancelSale(SaleService $sales): void
    {
        Gate::authorize(Permission::CancelSales->value);

        $this->validate([
            'owner_id' => ['required', 'integer'],
            'owner_password' => ['required', 'string'],
            'cancel_reason' => ['required', 'string', 'max:255'],
        ]);

        $owner = User::query()->where('is_active', true)->findOrFail($this->owner_id);
        $sales->cancel($this->sale, new SaleAuthorization($owner, $this->owner_password, $this->cancel_reason));

        $this->reset('owner_password', 'cancel_reason');
        $this->refreshSale();
        Flux::modal('cancel-sale')->close();

        Flux::toast(variant: 'success', text: __('La venta :folio quedó cancelada.', ['folio' => $this->sale->folio]));
    }

    private function refreshSale(): void
    {
        $this->sale = $this->sale->fresh();
        unset($this->items, $this->returnable, $this->pending, $this->history, $this->refundPreview);
    }

    /**
     * A quantity without trailing zeros: 6.000 → 6, 2.500 → 2.5.
     */
    public function format(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $returnedAny = $sale->customerReturns()->exists();
    $status = match (true) {
        $sale->isCanceled() => ['label' => __('Cancelada'), 'color' => 'zinc'],
        $returnedAny => ['label' => __('Devolución parcial'), 'color' => 'amber'],
        bccomp($this->pending, '0', 2) === 1 => ['label' => __('Por cobrar'), 'color' => 'red'],
        default => ['label' => __('Pagada'), 'color' => 'green'],
    };
    $cashPayment = $sale->payments->firstWhere('method', \App\Models\PaymentMethod::Cash);
    $canReturn = \Illuminate\Support\Facades\Gate::allows(\App\Models\Permission::RegisterReturns->value) && ! $sale->isCanceled() && collect($this->returnable)->contains(fn ($quantity) => bccomp($quantity, '0', 3) === 1);
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('sales.index')" wire:navigate>{{ __('Ventas') }}</flux:button>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl">{{ __('Venta :folio', ['folio' => $sale->folio]) }}</flux:heading>
                <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                <flux:badge size="sm" :color="$this->isInvoiced ? 'blue' : 'zinc'">{{ $this->isInvoiced ? __('Facturada') : __('Sin facturar') }}</flux:badge>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" icon="printer" :href="route('sales.ticket', $sale)" target="_blank">{{ __('Reimprimir ticket') }}</flux:button>
                <flux:button size="sm" icon="document-text" :href="route('sales.note', $sale)" target="_blank">{{ __('Nota de venta') }}</flux:button>
                @can(\App\Models\Permission::Invoice->value)
                    @if ($sale->customer_id && ! $sale->isCanceled() && ! $this->isInvoiced)
                        <flux:button size="sm" icon="document-text" :href="route('invoices.from-sales', ['venta' => $sale->id])" wire:navigate>{{ __('Facturar') }}</flux:button>
                    @endif
                @endcan
                @can(\App\Models\Permission::CancelSales->value)
                    @unless ($sale->isCanceled())
                        <flux:modal.trigger name="cancel-sale">
                            <flux:button size="sm" variant="danger" icon="x-circle" data-test="cancel-sale-trigger">{{ __('Cancelar venta') }}</flux:button>
                        </flux:modal.trigger>
                    @endunless
                @endcan
            </div>
        </div>
    </div>

    @if ($sale->isCanceled())
        <flux:callout variant="secondary" icon="x-circle" :heading="__('Cancelada el :date por :user', ['date' => $sale->canceled_at->translatedFormat('j M Y · H:i'), 'user' => $sale->canceledBy?->name])">
            <flux:callout.text>{{ __('Motivo: :reason', ['reason' => $sale->cancel_reason]) }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <flux:card class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><flux:text size="sm">{{ __('Fecha') }}</flux:text><div class="font-medium">{{ $sale->sold_at->translatedFormat('j M Y') }}</div><div class="text-sm text-zinc-500">{{ ucfirst(str_replace('.', '', $sale->sold_at->translatedFormat('l'))) }} · {{ $sale->sold_at->format('H:i') }}</div></div>
                <div><flux:text size="sm">{{ __('Cliente') }}</flux:text><div class="font-medium">{{ $sale->customer?->name ?? __('Público en general') }}</div></div>
                <div><flux:text size="sm">{{ __('Cuenta o destino') }}</flux:text><div class="font-medium">{{ $sale->customerDestination?->name ?? '—' }}</div></div>
                <div><flux:text size="sm">{{ __('Salió de') }}</flux:text><div class="font-medium">{{ $sale->warehouse->name }}</div></div>
                <div><flux:text size="sm">{{ __('Atendió') }}</flux:text><div class="font-medium">{{ $sale->user->name }}</div></div>
                @if ($sale->notes)
                    <flux:text class="sm:col-span-2 lg:col-span-3">{{ __('Observaciones: :notes', ['notes' => $sale->notes]) }}</flux:text>
                @endif
            </flux:card>

            <flux:card class="p-0">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Producto') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cant.') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Precio') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Importe') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->items as $item)
                            @php
                                $lots = $item->inventoryMovements
                                    ->map(fn ($movement) => __('lote :supplier :date', ['supplier' => $movement->lot->supplier?->name ?? __('propio'), 'date' => $movement->lot->received_at->translatedFormat('j M')]))
                                    ->unique()
                                    ->join(', ');
                            @endphp
                            <flux:table.row :key="$item->id">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $item->product->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ collect([$item->product->sku, $lots])->filter()->join(' · ') }}</span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $this->format($item->quantity) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $money($item->unit_price) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $money($item->subtotal) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                <div class="ms-auto flex max-w-xs flex-col gap-1 p-4 tabular-nums">
                    <div class="flex justify-between"><span>{{ __('Subtotal') }}</span><span>{{ $money($sale->subtotal) }}</span></div>
                    @if (bccomp($sale->discount_total, '0', 2) === 1)
                        <div class="flex justify-between"><span>{{ __('Descuento') }}</span><span>−{{ $money($sale->discount_total) }}</span></div>
                    @endif
                    @if (bccomp($sale->ieps_total, '0', 2) === 1)
                        <div class="flex justify-between"><span>{{ __('IEPS') }}</span><span>{{ $money($sale->ieps_total) }}</span></div>
                    @endif
                    @if (bccomp($sale->tax_total, '0', 2) === 1)
                        <div class="flex justify-between"><span>{{ __('IVA') }}</span><span>{{ $money($sale->tax_total) }}</span></div>
                    @endif
                    <div class="flex justify-between text-lg font-semibold"><span>{{ __('Total') }}</span><span>{{ $money($sale->total) }}</span></div>
                    @if ($cashPayment?->received_amount)
                        <flux:text size="sm" class="text-end">{{ __('Pagó :received en efectivo · cambio :change', ['received' => $money($cashPayment->received_amount), 'change' => $money($cashPayment->change_amount)]) }}</flux:text>
                    @endif
                    @if (! $sale->isCanceled() && bccomp($this->pending, '0', 2) === 1)
                        <flux:text size="sm" class="text-end font-medium text-red-700 dark:text-red-400">{{ __('Pendiente de cobro: :pending', ['pending' => $money($this->pending)]) }}</flux:text>
                    @endif
                </div>
            </flux:card>

            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Historial') }}</flux:heading>
                <ul class="flex flex-col gap-2">
                    @foreach ($this->history as $event)
                        <li class="text-sm"><span class="tabular-nums text-zinc-500">{{ $event['at']->translatedFormat('j M · H:i') }}</span> · {{ $event['text'] }}</li>
                    @endforeach
                </ul>
            </flux:card>
        </div>

        {{-- docs/diseno/VentaDetalle.html, "Registrar devolución" --}}
        <div>
            @if ($canReturn)
                <flux:card>
                    <form wire:submit="registerReturn" class="flex flex-col gap-4">
                        <div>
                            <flux:heading>{{ __('Registrar devolución') }}</flux:heading>
                            <flux:text size="sm">{{ __('Marca qué regresa el cliente') }}</flux:text>
                        </div>

                        @foreach ($this->items as $item)
                            @if (bccomp($this->returnable[$item->id], '0', 3) === 1)
                                <div class="flex items-center justify-between gap-3" wire:key="return-{{ $item->id }}">
                                    <span class="text-sm">{{ $item->product->name }}</span>
                                    <div class="flex items-center gap-2">
                                        <div class="w-20"><flux:input wire:model.live.debounce.300ms="return_quantities.{{ $item->id }}" type="number" min="0" step="any" size="sm" placeholder="0" /></div>
                                        <flux:text size="sm" class="whitespace-nowrap">{{ __('de :quantity', ['quantity' => $this->format($this->returnable[$item->id])]) }}</flux:text>
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        <flux:select wire:model="return_reason" :label="__('Motivo')" :placeholder="__('Elige…')">
                            <flux:select.option>{{ __('El cliente compró de más') }}</flux:select.option>
                            <flux:select.option>{{ __('Producto dañado') }}</flux:select.option>
                            <flux:select.option>{{ __('Error en la venta') }}</flux:select.option>
                        </flux:select>

                        <flux:radio.group wire:model.live="return_destination" :label="__('Regresa a')">
                            <flux:radio value="restock" :label="$sale->warehouse->name" />
                            <flux:radio value="waste" :label="__('Merma (producto dañado)')" />
                        </flux:radio.group>

                        <flux:radio.group wire:model.live="refund_method" :label="__('Se le regresa')" variant="segmented">
                            <flux:radio value="cash" :label="__('Efectivo')" />
                            @if ($sale->customer)
                                <flux:radio value="account_credit" :label="__('Abono a su cuenta')" />
                            @endif
                        </flux:radio.group>

                        <div class="flex items-center justify-between">
                            <flux:text>{{ bccomp($sale->discount_rate, '0', 4) === 1 || bccomp((string) $sale->customer?->discount_rate, '0', 4) === 1 ? __('A devolver (con su descuento)') : __('A devolver') }}</flux:text>
                            <span class="text-lg font-semibold tabular-nums">{{ $money($this->refundPreview) }}</span>
                        </div>

                        <flux:error name="items" />
                        <flux:error name="refund_method" />
                        <flux:error name="cash_session" />
                        <flux:error name="sale" />

                        <flux:button type="submit" variant="primary" data-test="register-return-button">{{ __('Registrar devolución') }}</flux:button>

                        @if (bccomp($this->refundPreview, '0', 2) === 1)
                            <flux:text size="sm">
                                {{ $return_destination === 'restock' ? __('Entra al kardex de :warehouse', ['warehouse' => $sale->warehouse->name]) : __('No regresa al inventario (merma)') }}
                                {{ $refund_method === 'cash' ? __('y sale :amount de caja.', ['amount' => $money($this->refundPreview)]) : __('y se abonan :amount a su cuenta.', ['amount' => $money($this->refundPreview)]) }}
                            </flux:text>
                        @endif
                    </form>
                </flux:card>
            @elseif (! $sale->isCanceled())
                <flux:text size="sm">{{ __('Todos los productos de esta venta ya se devolvieron.') }}</flux:text>
            @endif
        </div>
    </div>

    {{-- docs/diseno/CancelarVenta.html --}}
    @can(\App\Models\Permission::CancelSales->value)
        <flux:modal name="cancel-sale" class="md:w-[28rem]">
            <form wire:submit="cancelSale" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Cancelar venta :folio', ['folio' => $sale->folio]) }}</flux:heading>
                    <flux:text>{{ __('Cliente: :customer', ['customer' => $sale->customer?->name ?? __('Público en general')]) }}</flux:text>
                </div>

                <flux:text>
                    {{ __('Regresan a :warehouse: :items', ['warehouse' => $sale->warehouse->name, 'items' => $this->items->map(fn ($item) => $this->format($item->quantity).' '.$item->product->name)->join(', ')]) }}
                </flux:text>
                @if ($cashPayment)
                    <flux:text>{{ __('Se devuelve en efectivo :amount', ['amount' => $money($sale->payments->where('method', \App\Models\PaymentMethod::Cash)->sum('amount'))]) }}</flux:text>
                @endif
                <flux:text size="sm">{{ __('La venta no se borra: queda marcada como cancelada, con su motivo, en el historial.') }}</flux:text>

                <flux:select wire:model="cancel_reason" :label="__('Motivo')" :placeholder="__('Elige…')">
                    <flux:select.option>{{ __('El cliente se arrepintió') }}</flux:select.option>
                    <flux:select.option>{{ __('Venta capturada por error') }}</flux:select.option>
                    <flux:select.option>{{ __('Precio equivocado') }}</flux:select.option>
                </flux:select>
                @if ($this->owners->count() > 1)
                    <flux:select wire:model="owner_id" :label="__('Dueño que autoriza')">
                        @foreach ($this->owners as $owner)
                            <flux:select.option :value="$owner->id">{{ $owner->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:input wire:model="owner_password" :label="__('Contraseña del dueño')" type="password" autocomplete="off" viewable />

                <flux:error name="authorization" />
                <flux:error name="sale" />
                <flux:error name="cash_session" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('No, regresar') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" data-test="cancel-sale-button">{{ __('Sí, cancelar venta') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>

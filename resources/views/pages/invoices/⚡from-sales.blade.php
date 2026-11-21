<?php

use App\Actions\Facturapi\StampInvoice;
use App\Models\Customer;
use App\Models\InvoiceStatus;
use App\Models\Permission;
use App\Models\Sale;
use App\Services\InvoiceFromSalesService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Nueva factura')] class extends Component {
    #[Url(as: 'cliente', except: null)]
    public ?int $customer_id = null;

    /** @var list<int> */
    public array $sale_ids = [];

    public string $cfdi_use = 'G01';

    public function mount(): void
    {
        $saleId = (int) request()->query('venta');
        $sale = $saleId ? Sale::query()->find($saleId) : null;

        if ($sale?->customer_id) {
            $this->customer_id = $sale->customer_id;
        }

        $this->loadCustomer();

        if ($sale && $this->uninvoiced->contains(fn (array $row) => $row['sale']->is($sale))) {
            $this->sale_ids = [$sale->id];
        }
    }

    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'rfc']);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customer_id ? Customer::query()->find($this->customer_id) : null;
    }

    #[Computed]
    public function uninvoiced(): Collection
    {
        return $this->customer ? app(InvoiceFromSalesService::class)->uninvoicedSales($this->customer) : collect();
    }

    #[Computed]
    public function selectedSales(): Collection
    {
        return Sale::query()->with('payments')->whereKey($this->sale_ids)->where('customer_id', $this->customer_id)->get();
    }

    #[Computed]
    public function concepts(): Collection
    {
        return app(InvoiceFromSalesService::class)->concepts($this->selectedSales);
    }

    /**
     * @return array{payment_method: string, payment_form: string}
     */
    #[Computed]
    public function terms(): array
    {
        return app(InvoiceFromSalesService::class)->paymentTerms($this->selectedSales);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function blockers(): array
    {
        return $this->customer ? app(InvoiceFromSalesService::class)->blockers($this->customer, $this->concepts) : [];
    }

    public function updatedCustomerId(): void
    {
        $this->sale_ids = [];
        $this->loadCustomer();
    }

    public function stamp(InvoiceFromSalesService $invoices, StampInvoice $stampInvoice): void
    {
        Gate::authorize(Permission::Invoice->value);

        $this->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'cfdi_use' => ['required', 'string', 'max:4'],
            'sale_ids' => ['array'],
        ]);

        $invoice = $stampInvoice->handle($invoices->create($this->customer, array_map('intval', $this->sale_ids), $this->cfdi_use));

        if ($invoice->status === InvoiceStatus::Stamped) {
            Flux::toast(variant: 'success', text: __('Factura timbrada correctamente.'));
        } else {
            Flux::toast(variant: 'danger', text: __('La factura se guardó como borrador, pero no se pudo timbrar: ').$invoice->error_message);
        }

        $this->redirectRoute('invoices.index', navigate: true);
    }

    public function paymentFormLabel(string $code): string
    {
        return match ($code) {
            '01' => __('Efectivo'),
            '03' => __('Transferencia'),
            '04' => __('Tarjeta'),
            default => __('Por definir'),
        };
    }

    private function loadCustomer(): void
    {
        unset($this->customer, $this->uninvoiced, $this->selectedSales, $this->concepts, $this->terms, $this->blockers);
        $this->cfdi_use = $this->customer?->cfdi_use ?: 'G01';
    }

    /**
     * A quantity without trailing zeros: 3.000 → 3, 2.500 → 2.5.
     */
    public function format(string $quantity): string
    {
        return str_contains($quantity, '.') ? (rtrim(rtrim($quantity, '0'), '.') ?: '0') : $quantity;
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $concepts = $this->concepts;
    $sum = fn (string $key) => $concepts->reduce(fn ($carry, $line) => bcadd($carry, $line[$key], 2), '0');
    $subtotal = bcsub($sum('subtotal'), $sum('discount'), 2);
    $taxes = bcadd($sum('tax_amount'), $sum('ieps_amount'), 2);
    $total = $sum('total');
    $customer = $this->customer;
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Nueva factura') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Se arma desde las ventas: productos, precios e impuestos ya vienen llenos') }}</flux:text>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('1. Cliente') }}</flux:heading>
                <flux:select wire:model.live="customer_id" :label="__('Cliente')" :placeholder="__('Elige…')">
                    @foreach ($this->customers as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}{{ $option->rfc ? ' · '.$option->rfc : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($customer)
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <div><flux:text size="sm">{{ __('RFC') }}</flux:text>{{ $customer->rfc ?? __('[POR CAPTURAR]') }}</div>
                        <div><flux:text size="sm">{{ __('Régimen') }}</flux:text>{{ $customer->tax_system ?? __('[POR CAPTURAR]') }}</div>
                        <div><flux:text size="sm">{{ __('CP fiscal') }}</flux:text>{{ $customer->zip_code ?? __('[POR CAPTURAR]') }}</div>
                    </div>
                    <flux:select wire:model="cfdi_use" :label="__('Uso del CFDI')">
                        <flux:select.option value="G01">{{ __('G01 · Adquisición de mercancías') }}</flux:select.option>
                        <flux:select.option value="G03">{{ __('G03 · Gastos en general') }}</flux:select.option>
                        <flux:select.option value="S01">{{ __('S01 · Sin efectos fiscales') }}</flux:select.option>
                    </flux:select>
                    <flux:link :href="route('customers.show', $customer)" wire:navigate class="text-sm">{{ __('Editar datos fiscales del cliente') }}</flux:link>
                @endif
            </flux:card>

            @if ($customer)
                <flux:card class="flex flex-col gap-3 p-0">
                    <flux:heading class="px-4 pt-4">{{ __('2. Ventas sin facturar') }}</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Incluir') }}</flux:table.column>
                            <flux:table.column>{{ __('Venta') }}</flux:table.column>
                            <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                            <flux:table.column>{{ __('Pago') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->uninvoiced as $row)
                                <flux:table.row :key="$row['sale']->id">
                                    <flux:table.cell><flux:checkbox wire:model.live="sale_ids" :value="$row['sale']->id" :aria-label="__('Incluir :folio', ['folio' => $row['sale']->folio])" /></flux:table.cell>
                                    <flux:table.cell><flux:link :href="route('sales.show', $row['sale'])" wire:navigate>{{ $row['sale']->folio }}</flux:link></flux:table.cell>
                                    <flux:table.cell>{{ $row['sale']->sold_at->translatedFormat('j M') }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['payment'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $money($row['sale']->total) }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5"><flux:text class="py-4 text-center">{{ __('Este cliente no tiene ventas sin facturar.') }}</flux:text></flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                    <flux:text size="sm" class="px-4 pb-4">{{ __('Una venta a crédito sin pagar se factura con método "en parcialidades o diferido".') }}</flux:text>
                </flux:card>

                @if ($concepts->isNotEmpty())
                    <flux:card class="flex flex-col gap-3 p-0">
                        <flux:heading class="px-4 pt-4">{{ __('3. Conceptos') }}</flux:heading>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Producto') }}</flux:table.column>
                                <flux:table.column>{{ __('Clave SAT') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Cant.') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Precio') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Importe') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($concepts as $index => $line)
                                    <flux:table.row :key="'concept-'.$index">
                                        <flux:table.cell class="font-medium">{{ $line['product']->name }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if (filled($line['product']->product_key) && filled($line['product']->unit_key))
                                                <span class="text-sm text-zinc-500">{{ __('En la ficha') }}</span>
                                            @else
                                                <flux:link :href="route('products.edit', $line['product'])" wire:navigate class="text-sm text-red-700 dark:text-red-400">{{ __('Falta · completar') }}</flux:link>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell align="end" class="tabular-nums">{{ $this->format($line['quantity']) }}</flux:table.cell>
                                        <flux:table.cell align="end" class="tabular-nums">{{ $money($line['unit_price']) }}</flux:table.cell>
                                        <flux:table.cell align="end" class="tabular-nums">
                                            {{ $money($line['subtotal']) }}
                                            @if (bccomp($line['discount'], '0', 2) === 1)
                                                <span class="block text-xs text-zinc-500">{{ __('desc. −:amount', ['amount' => $money($line['discount'])]) }}</span>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                @endif
            @endif
        </div>

        <div>
            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('4. Revisar y emitir') }}</flux:heading>
                @if ($concepts->isEmpty())
                    <flux:text size="sm">{{ __('Elige el cliente y las ventas que se facturan.') }}</flux:text>
                @else
                    <div class="flex justify-between text-sm"><span>{{ __('Forma de pago') }}</span><span>{{ $this->paymentFormLabel($this->terms['payment_form']) }}{{ $this->terms['payment_method'] === 'PUE' && $this->terms['payment_form'] !== '99' ? ' '.__('(la de mayor monto)') : '' }}</span></div>
                    <div class="flex justify-between text-sm"><span>{{ __('Método de pago') }}</span><span>{{ $this->terms['payment_method'] === 'PPD' ? __('En parcialidades o diferido') : __('Una sola exhibición') }}</span></div>
                    <div class="flex flex-col gap-1 border-t border-line pt-3 tabular-nums dark:border-zinc-700">
                        <div class="flex justify-between"><span>{{ __('Subtotal') }}</span><span>{{ $money($subtotal) }}</span></div>
                        <div class="flex justify-between"><span>{{ __('IVA / IEPS según producto') }}</span><span>{{ $money($taxes) }}</span></div>
                        <div class="flex justify-between text-lg font-semibold"><span>{{ __('Total') }}</span><span>{{ $money($total) }}</span></div>
                    </div>
                @endif

                @foreach ($this->blockers as $blocker)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$blocker" />
                @endforeach
                <flux:error name="sale_ids" />
                <flux:error name="invoice" />

                <flux:button variant="primary" wire:click="stamp" :disabled="$concepts->isEmpty() || $this->blockers !== []" data-test="stamp-invoice-button">{{ __('Timbrar factura') }}</flux:button>
            </flux:card>
        </div>
    </div>
</div>

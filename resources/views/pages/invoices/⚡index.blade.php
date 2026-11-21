<?php

use App\Actions\Facturapi\CancelFacturapiInvoice;
use App\Actions\Facturapi\StampInvoice;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\InvoiceType;
use App\Models\Permission;
use App\Services\InvoicePaymentService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Facturas')] class extends Component {
    use WithPagination;

    #[Url(as: 'factura', except: null)]
    public ?int $selectedId = null;

    public string $cancellation_reason = '02';

    public string $substitution_uuid = '';

    public ?int $complementPaymentId = null;

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['customer', 'sales', 'complementPayments.relatedInvoice'])
            ->latest()
            ->latest('id')
            ->paginate(15);
    }

    #[Computed]
    public function selected(): ?Invoice
    {
        return $this->selectedId ? Invoice::query()->with(['customer', 'sales', 'paidInstallments.invoice', 'complementPayments.relatedInvoice'])->find($this->selectedId) : null;
    }

    /**
     * What is left to pay of a PPD invoice after its valid complements.
     *
     * @return array{paid: string, balance: string}
     */
    #[Computed]
    public function installmentBalance(): array
    {
        $invoice = $this->selected;
        $paid = $invoice->paidInstallments
            ->filter(fn ($installment) => $installment->invoice->status === InvoiceStatus::Stamped)
            ->reduce(fn (string $carry, $installment) => bcadd($carry, $installment->amount_paid, 2), '0.00');

        return ['paid' => $paid, 'balance' => bcsub($invoice->total, $paid, 2)];
    }

    /**
     * The installment about to be stamped, for the complement dialog.
     *
     * @return array{invoice: Invoice, payment: CustomerPayment, installment: int, previous_balance: string, amount_paid: string, outstanding_balance: string}|null
     */
    #[Computed]
    public function complement(): ?array
    {
        if ($this->selected === null || $this->complementPaymentId === null) {
            return null;
        }

        $payment = CustomerPayment::query()->findOrFail($this->complementPaymentId);

        return ['invoice' => $this->selected, 'payment' => $payment, ...app(InvoicePaymentService::class)->prepare($this->selected, $payment)];
    }

    public function pendingComplements(Invoice $invoice): int
    {
        return app(InvoicePaymentService::class)->pendingComplements($invoice)->count();
    }

    public function select(int $invoiceId): void
    {
        $this->selectedId = $this->selectedId === $invoiceId ? null : $invoiceId;
        unset($this->selected, $this->installmentBalance);
    }

    /**
     * Open the complement of the oldest payment still without one.
     */
    public function startComplement(): void
    {
        $this->complementPaymentId = app(InvoicePaymentService::class)->pendingComplements($this->selected)->first()['payment']->id ?? null;
        unset($this->complement);

        Flux::modal('payment-complement')->show();
    }

    public function stampComplement(InvoicePaymentService $complements): void
    {
        Gate::authorize(Permission::Invoice->value);

        $complements->stamp($this->selected, CustomerPayment::query()->findOrFail($this->complementPaymentId));

        $this->complementPaymentId = null;
        unset($this->complement, $this->invoices, $this->selected, $this->installmentBalance);
        Flux::modal('payment-complement')->close();
        Flux::toast(variant: 'success', text: __('Complemento de pago timbrado.'));
    }

    /**
     * Try again to stamp an invoice that stayed as a draft or failed.
     */
    public function retryStamp(StampInvoice $stampInvoice): void
    {
        Gate::authorize(Permission::Invoice->value);

        $invoice = $stampInvoice->handle($this->selected);
        unset($this->invoices, $this->selected);

        $invoice->status === InvoiceStatus::Stamped
            ? Flux::toast(variant: 'success', text: __('Factura timbrada correctamente.'))
            : Flux::toast(variant: 'danger', text: __('No se pudo timbrar: ').$invoice->error_message);
    }

    public function confirmCancel(): void
    {
        $this->reset('cancellation_reason', 'substitution_uuid');
        $this->resetErrorBag();

        Flux::modal('invoice-cancel')->show();
    }

    public function cancel(CancelFacturapiInvoice $cancelInvoice): void
    {
        Gate::authorize(Permission::CancelInvoices->value);

        $this->resetErrorBag();
        $cancelInvoice->handle($this->selected, $this->cancellation_reason, $this->substitution_uuid ?: null);

        unset($this->invoices, $this->selected);
        Flux::modal('invoice-cancel')->close();
        Flux::toast(variant: 'success', text: __('Factura cancelada.'));
    }

    /**
     * @return array{label: string, color: string}
     */
    public function statusOf(Invoice $invoice): array
    {
        return match (true) {
            $invoice->status === InvoiceStatus::Canceled => ['label' => __('Cancelada'), 'color' => 'zinc'],
            $invoice->status === InvoiceStatus::Failed => ['label' => __('No se timbró'), 'color' => 'red'],
            $invoice->status === InvoiceStatus::Draft => ['label' => __('Borrador'), 'color' => 'zinc'],
            $this->pendingComplements($invoice) > 0 => ['label' => __('Falta complemento'), 'color' => 'amber'],
            default => ['label' => __('Vigente'), 'color' => 'green'],
        };
    }

    public function methodOf(Invoice $invoice): string
    {
        return match (true) {
            $invoice->type === InvoiceType::Payment => __('Complemento de pago'),
            $invoice->payment_method === 'PPD' => __('Parcialidades'),
            default => __('Una exhibición'),
        };
    }

    /**
     * "Ventas V-001018 y V-001007", or the invoice a payment complement pays.
     */
    public function coversOf(Invoice $invoice): string
    {
        if ($invoice->type === InvoiceType::Payment) {
            $related = $invoice->complementPayments->first()?->relatedInvoice;

            return $related ? __('Pago de :folio', ['folio' => $this->folioOf($related)]) : '';
        }

        if ($invoice->sales->isEmpty()) {
            return '';
        }

        return trans_choice('Venta :folios|Ventas :folios', $invoice->sales->count(), ['folios' => $invoice->sales->pluck('folio')->join(', ', ' y ')]);
    }

    public function folioOf(Invoice $invoice): string
    {
        return $invoice->series || $invoice->folio ? trim($invoice->series.'-'.$invoice->folio, '-') : __('Sin folio');
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $selected = $this->selected;
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Facturas emitidas') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Facturas y complementos de pago timbrados con Facturapi.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('invoices.from-sales')" wire:navigate>{{ __('Nueva factura') }}</flux:button>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="p-0 {{ $selected ? 'lg:col-span-2' : 'lg:col-span-3' }}">
            <flux:table :paginate="$this->invoices">
                <flux:table.columns>
                    <flux:table.column>{{ __('Folio') }}</flux:table.column>
                    <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                    <flux:table.column>{{ __('Cliente') }}</flux:table.column>
                    <flux:table.column>{{ __('Método') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Estado') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->invoices as $invoice)
                        @php
                            $status = $this->statusOf($invoice);
                        @endphp
                        <flux:table.row :key="$invoice->id" class="{{ $selectedId === $invoice->id ? 'bg-surface dark:bg-zinc-800' : '' }}">
                            <flux:table.cell>
                                <button type="button" wire:click="select({{ $invoice->id }})" class="text-start font-medium text-link hover:underline" data-test="select-invoice-{{ $invoice->id }}">{{ $this->folioOf($invoice) }}</button>
                                <span class="block text-sm text-zinc-500">{{ $this->coversOf($invoice) }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ ($invoice->stamped_at ?? $invoice->created_at)->translatedFormat('j M') }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->customer->name }}</flux:table.cell>
                            <flux:table.cell>{{ $this->methodOf($invoice) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $money($invoice->total) }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6"><flux:text class="py-6 text-center">{{ __('Aún no hay facturas. Se arman desde las ventas con "Nueva factura".') }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        @if ($selected)
            @php
                $status = $this->statusOf($selected);
                $pending = app(\App\Services\InvoicePaymentService::class)->pendingComplements($selected);
            @endphp
            <flux:card class="flex flex-col gap-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg">{{ $selected->type === \App\Models\InvoiceType::Payment ? __('Complemento :folio', ['folio' => $this->folioOf($selected)]) : __('Factura :folio', ['folio' => $this->folioOf($selected)]) }}</flux:heading>
                        <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                    </div>
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="select({{ $selected->id }})" :aria-label="__('Cerrar')" />
                </div>

                <div class="flex flex-col gap-2 text-sm">
                    <div class="flex justify-between gap-3"><span class="text-zinc-500">{{ __('Cliente') }}</span><span>{{ $selected->customer->name }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-zinc-500">{{ __('Folio fiscal') }}</span><span class="break-all text-end font-mono text-xs">{{ $selected->uuid ?? '—' }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-zinc-500">{{ __('Método') }}</span><span>{{ $selected->payment_method === 'PPD' ? __('En parcialidades o diferido') : $this->methodOf($selected) }}</span></div>
                    <div class="flex justify-between gap-3 font-semibold"><span>{{ __('Total') }}</span><span class="tabular-nums">{{ $money($selected->total) }}</span></div>
                    @if ($selected->isPaidInInstallments())
                        <div class="flex justify-between gap-3"><span class="text-zinc-500">{{ __('Pagado con complemento') }}</span><span class="tabular-nums">{{ $money($this->installmentBalance['paid']) }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-zinc-500">{{ __('Saldo') }}</span><span class="tabular-nums">{{ $money($this->installmentBalance['balance']) }}</span></div>
                    @endif
                    @if ($selected->status === \App\Models\InvoiceStatus::Canceled)
                        <div class="flex justify-between gap-3"><span class="text-zinc-500">{{ __('Cancelada') }}</span><span>{{ $selected->canceled_at?->translatedFormat('j M Y') }} · {{ __('motivo :motive', ['motive' => $selected->cancellation_reason ?? '02']) }}</span></div>
                    @endif
                </div>

                @if ($selected->error_message && $selected->status !== \App\Models\InvoiceStatus::Stamped)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$selected->error_message" />
                @endif

                @if ($pending->isNotEmpty())
                    @php
                        $nextPayment = $pending->first();
                    @endphp
                    <flux:callout variant="warning" icon="banknotes">
                        <flux:callout.text>{{ __(':date abonó :amount en :method y falta emitir su complemento de pago.', ['date' => ucfirst($nextPayment['payment']->paid_at->isToday() ? __('hoy') : $nextPayment['payment']->paid_at->translatedFormat('j M')), 'amount' => $money($nextPayment['amount']), 'method' => mb_strtolower($nextPayment['payment']->method->label())]) }}</flux:callout.text>
                    </flux:callout>
                    @can(\App\Models\Permission::Invoice->value)
                        <flux:button variant="primary" icon="document-plus" wire:click="startComplement" data-test="start-complement-button">{{ __('Emitir complemento de pago') }}</flux:button>
                    @endcan
                @endif

                <div class="flex flex-wrap gap-2">
                    @if ($selected->status === \App\Models\InvoiceStatus::Stamped)
                        <flux:button size="sm" icon="document" :href="route('invoices.pdf', $selected)" target="_blank">{{ __('PDF') }}</flux:button>
                        <flux:button size="sm" icon="code-bracket" :href="route('invoices.xml', $selected)" target="_blank">{{ __('XML') }}</flux:button>
                    @elseif (in_array($selected->status, [\App\Models\InvoiceStatus::Draft, \App\Models\InvoiceStatus::Failed], true))
                        @can(\App\Models\Permission::Invoice->value)
                            <flux:button size="sm" icon="arrow-path" wire:click="retryStamp" data-test="retry-stamp-button">{{ __('Reintentar timbrado') }}</flux:button>
                        @endcan
                    @endif
                </div>

                @if ($selected->status === \App\Models\InvoiceStatus::Stamped)
                    @can(\App\Models\Permission::CancelInvoices->value)
                        <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="confirmCancel" data-test="cancel-invoice-trigger">{{ __('Cancelar factura (dueño)') }}</flux:button>
                    @endcan
                @endif
            </flux:card>
        @endif
    </div>

    <flux:modal name="invoice-cancel" class="md:w-[28rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('¿Cancelar factura?') }}</flux:heading>
            <flux:text>{{ __('Se cancelará ante el SAT. Esta acción no se puede deshacer.') }}</flux:text>
            <flux:select wire:model.live="cancellation_reason" :label="__('Motivo SAT')">
                <flux:select.option value="01">{{ __('01 · Con errores, con relación (se sustituye)') }}</flux:select.option>
                <flux:select.option value="02">{{ __('02 · Con errores, sin relación') }}</flux:select.option>
                <flux:select.option value="03">{{ __('03 · No se llevó a cabo la operación') }}</flux:select.option>
                <flux:select.option value="04">{{ __('04 · Operación nominativa en factura global') }}</flux:select.option>
            </flux:select>
            @if ($cancellation_reason === '01')
                <flux:input wire:model="substitution_uuid" :label="__('Folio fiscal de la factura que la sustituye')" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" />
            @endif
            <flux:error name="cancellation_reason" />
            <flux:error name="substitution_uuid" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cerrar') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="cancel" data-test="cancel-invoice-button">{{ __('Cancelar factura') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- docs/diseno/ComplementoPago.html --}}
    <flux:modal name="payment-complement" class="md:w-[28rem]">
        @if ($complement = $this->complement)
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Complemento de pago') }}</flux:heading>
                    <flux:text>{{ __('Factura :folio · :customer · en parcialidades', ['folio' => $this->folioOf($complement['invoice']), 'customer' => $complement['invoice']->customer->name]) }}</flux:text>
                </div>
                <flux:callout icon="banknotes">
                    <flux:callout.heading>{{ __('Abono que ampara: :date · :amount · :method', ['date' => $complement['payment']->paid_at->translatedFormat('j M'), 'amount' => $money($complement['amount_paid']), 'method' => mb_strtolower($complement['payment']->method->label())]) }}</flux:callout.heading>
                    <flux:callout.text>{{ __('Se toma del abono ya registrado en su estado de cuenta.') }}</flux:callout.text>
                </flux:callout>
                <div class="flex flex-col gap-1 tabular-nums">
                    <div class="flex justify-between"><span>{{ __('Parcialidad') }}</span><span>{{ $complement['installment'] }}</span></div>
                    <div class="flex justify-between"><span>{{ __('Saldo anterior') }}</span><span>{{ $money($complement['previous_balance']) }}</span></div>
                    <div class="flex justify-between"><span>{{ __('Pago') }}</span><span>−{{ $money($complement['amount_paid']) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>{{ __('Saldo pendiente') }}</span><span>{{ $money($complement['outstanding_balance']) }}</span></div>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div><flux:text size="sm">{{ __('Fecha del pago') }}</flux:text>{{ $complement['payment']->paid_at->format('d/m/Y') }}</div>
                    <div><flux:text size="sm">{{ __('Forma de pago') }}</flux:text>{{ $complement['payment']->method->label() }}</div>
                </div>
                <flux:text size="sm">{{ __('El SAT pide emitirlo a más tardar el día 5 del mes siguiente al pago; confirma el plazo con el contador.') }}</flux:text>
                <flux:error name="complement" />
                <flux:error name="customer_payment_id" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="stampComplement" data-test="stamp-complement-button">{{ __('Timbrar complemento') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>

<?php

use App\Actions\Facturapi\CancelFacturapiInvoice;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\InvoiceType;
use App\Services\InvoicePaymentService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Facturas')] class extends Component
{
    use WithPagination;

    public ?int $cancelingId = null;

    public string $cancellation_reason = '02';

    public string $substitution_uuid = '';

    public ?int $complementInvoiceId = null;

    public ?int $complementPaymentId = null;

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    #[Computed]
    public function invoices()
    {
        return Invoice::query()->with('customer')->latest()->paginate(10);
    }

    /**
     * The installment about to be stamped, for the complement dialog.
     *
     * @return array{invoice: Invoice, payment: CustomerPayment, installment: int, previous_balance: string, amount_paid: string, outstanding_balance: string}|null
     */
    #[Computed]
    public function complement(): ?array
    {
        if ($this->complementInvoiceId === null || $this->complementPaymentId === null) {
            return null;
        }

        $invoice = Invoice::query()->with('customer')->findOrFail($this->complementInvoiceId);
        $payment = CustomerPayment::query()->findOrFail($this->complementPaymentId);

        return ['invoice' => $invoice, 'payment' => $payment, ...app(InvoicePaymentService::class)->prepare($invoice, $payment)];
    }

    public function pendingComplements(Invoice $invoice): int
    {
        return app(InvoicePaymentService::class)->pendingComplements($invoice)->count();
    }

    /**
     * Open the complement of the oldest payment still without one.
     */
    public function startComplement(int $invoiceId): void
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->complementInvoiceId = $invoice->id;
        $this->complementPaymentId = app(InvoicePaymentService::class)->pendingComplements($invoice)->first()['payment']->id ?? null;
        unset($this->complement);

        Flux::modal('payment-complement')->show();
    }

    public function stampComplement(InvoicePaymentService $complements): void
    {
        $complements->stamp(Invoice::query()->findOrFail($this->complementInvoiceId), CustomerPayment::query()->findOrFail($this->complementPaymentId));

        $this->reset('complementInvoiceId', 'complementPaymentId');
        unset($this->complement, $this->invoices);
        Flux::modal('payment-complement')->close();
        Flux::toast(variant: 'success', text: __('Complemento de pago timbrado.'));
    }

    public function confirmCancel(int $invoiceId): void
    {
        $this->cancelingId = $invoiceId;
        $this->reset('cancellation_reason', 'substitution_uuid');
        $this->resetErrorBag();

        Flux::modal('invoice-cancel')->show();
    }

    public function cancel(CancelFacturapiInvoice $cancelInvoice): void
    {
        $invoice = Invoice::findOrFail($this->cancelingId);

        $this->resetErrorBag();
        $cancelInvoice->handle($invoice, $this->cancellation_reason, $this->substitution_uuid ?: null);

        $this->cancelingId = null;

        Flux::modal('invoice-cancel')->close();

        Flux::toast(variant: 'success', text: __('Factura cancelada.'));
    }

    public function statusColor(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Draft => 'zinc',
            InvoiceStatus::Stamped => 'green',
            InvoiceStatus::Canceled => 'red',
            InvoiceStatus::Failed => 'amber',
        };
    }

    public function statusLabel(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Draft => __('Borrador'),
            InvoiceStatus::Stamped => __('Timbrada'),
            InvoiceStatus::Canceled => __('Cancelada'),
            InvoiceStatus::Failed => __('Fallida'),
        };
    }
}; ?>

<div class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Facturas') }}</flux:heading>
            <flux:subheading>{{ __('Facturas emitidas a través de Facturapi.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('invoices.from-sales')" wire:navigate>
            {{ __('Nueva factura') }}
        </flux:button>
    </div>

    <flux:table :paginate="$this->invoices">
        <flux:table.columns>
            <flux:table.column>{{ __('Cliente') }}</flux:table.column>
            <flux:table.column>{{ __('Folio') }}</flux:table.column>
            <flux:table.column>{{ __('Total') }}</flux:table.column>
            <flux:table.column>{{ __('Estado') }}</flux:table.column>
            <flux:table.column>{{ __('Fecha') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->invoices as $invoice)
                <flux:table.row :key="$invoice->id">
                    <flux:table.cell>{{ $invoice->customer->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $invoice->series }}{{ $invoice->folio }}{{ $invoice->series || $invoice->folio ? '' : '—' }}
                        @if ($invoice->type === InvoiceType::Payment)
                            <span class="block text-xs text-zinc-500">{{ __('Complemento de pago') }}</span>
                        @elseif ($invoice->payment_method === 'PPD')
                            <span class="block text-xs text-zinc-500">{{ __('En parcialidades') }}</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>${{ number_format((float) $invoice->total, 2) }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:badge size="sm" :color="$this->statusColor($invoice->status)">
                            {{ $this->statusLabel($invoice->status) }}
                        </flux:badge>
                        @php
                            $pendingComplements = $this->pendingComplements($invoice);
                        @endphp
                        @if ($pendingComplements > 0)
                            <flux:badge size="sm" color="amber">{{ __('Falta complemento') }}</flux:badge>
                        @endif
                        @if ($invoice->status === InvoiceStatus::Failed && $invoice->error_message)
                            <flux:tooltip content="{{ $invoice->error_message }}">
                                <flux:icon name="information-circle" class="ml-1 inline size-4 text-amber-600" />
                            </flux:tooltip>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $invoice->created_at->format('d/m/Y') }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            @if ($pendingComplements > 0)
                                <flux:button size="sm" variant="primary" icon="document-plus" wire:click="startComplement({{ $invoice->id }})" data-test="start-complement-button">
                                    {{ __('Complemento') }}
                                </flux:button>
                            @endif
                            @if ($invoice->status === InvoiceStatus::Stamped)
                                <flux:button size="sm" icon="document" :href="route('invoices.pdf', $invoice)" target="_blank">
                                    {{ __('PDF') }}
                                </flux:button>
                                <flux:button size="sm" icon="code-bracket" :href="route('invoices.xml', $invoice)" target="_blank">
                                    {{ __('XML') }}
                                </flux:button>
                                <flux:button size="sm" variant="danger" icon="x-circle" wire:click="confirmCancel({{ $invoice->id }})">
                                    {{ __('Cancelar') }}
                                </flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                        {{ __('Aún no hay facturas.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="invoice-cancel" class="md:w-96">
        <div class="space-y-6">
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
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cerrar') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancel">{{ __('Cancelar factura') }}</flux:button>
            </div>
        </div>
    </flux:modal>
    {{-- docs/diseno/ComplementoPago.html --}}
    <flux:modal name="payment-complement" class="md:w-[28rem]">
        @if ($complement = $this->complement)
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Complemento de pago') }}</flux:heading>
                    <flux:text>{{ __('Factura :folio · :customer · en parcialidades', ['folio' => $complement['invoice']->series.'-'.$complement['invoice']->folio, 'customer' => $complement['invoice']->customer->name]) }}</flux:text>
                </div>
                <flux:callout icon="banknotes">
                    <flux:callout.heading>{{ __('Abono que ampara: :date · :amount · :method', ['date' => $complement['payment']->paid_at->translatedFormat('j M'), 'amount' => '$'.number_format((float) $complement['amount_paid'], 2), 'method' => mb_strtolower($complement['payment']->method->label())]) }}</flux:callout.heading>
                    <flux:callout.text>{{ __('Se toma del abono ya registrado en su estado de cuenta.') }}</flux:callout.text>
                </flux:callout>
                <div class="flex flex-col gap-1 tabular-nums">
                    <div class="flex justify-between"><span>{{ __('Parcialidad') }}</span><span>{{ $complement['installment'] }}</span></div>
                    <div class="flex justify-between"><span>{{ __('Saldo anterior') }}</span><span>${{ number_format((float) $complement['previous_balance'], 2) }}</span></div>
                    <div class="flex justify-between"><span>{{ __('Pago') }}</span><span>−${{ number_format((float) $complement['amount_paid'], 2) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>{{ __('Saldo pendiente') }}</span><span>${{ number_format((float) $complement['outstanding_balance'], 2) }}</span></div>
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

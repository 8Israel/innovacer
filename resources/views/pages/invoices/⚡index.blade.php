<?php

use App\Actions\Facturapi\CancelFacturapiInvoice;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
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

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    #[Computed]
    public function invoices()
    {
        return Invoice::query()->with('customer')->latest()->paginate(10);
    }

    public function confirmCancel(int $invoiceId): void
    {
        $this->cancelingId = $invoiceId;

        Flux::modal('invoice-cancel')->show();
    }

    public function cancel(CancelFacturapiInvoice $cancelInvoice): void
    {
        $invoice = Invoice::findOrFail($this->cancelingId);

        $cancelInvoice->handle($invoice);

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

        <flux:button variant="primary" icon="plus" :href="route('invoices.create')" wire:navigate>
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
                    <flux:table.cell>{{ $invoice->series }}{{ $invoice->folio }}{{ $invoice->series || $invoice->folio ? '' : '—' }}</flux:table.cell>
                    <flux:table.cell>${{ number_format((float) $invoice->total, 2) }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:badge size="sm" :color="$this->statusColor($invoice->status)">
                            {{ $this->statusLabel($invoice->status) }}
                        </flux:badge>
                        @if ($invoice->status === InvoiceStatus::Failed && $invoice->error_message)
                            <flux:tooltip content="{{ $invoice->error_message }}">
                                <flux:icon name="information-circle" class="ml-1 inline size-4 text-amber-600" />
                            </flux:tooltip>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $invoice->created_at->format('d/m/Y') }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
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

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cerrar') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancel">{{ __('Cancelar factura') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

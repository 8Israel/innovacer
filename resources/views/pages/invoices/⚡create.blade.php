<?php

use App\Actions\Facturapi\StampInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Product;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nueva factura')] class extends Component
{
    public ?int $customer_id = null;

    public string $customerSearch = '';

    /** @var array<int, array{id: int, name: string, rfc: string}> */
    public array $customerResults = [];

    public string $payment_form = '';

    public string $payment_method = '';

    public string $cfdi_use = '';

    /** @var array<string, array{product_id: ?int, quantity: string, search: string, results: array<int, array{id: int, name: string, sku: string, unit_price: string}>}> */
    public array $items = [];

    public string $barcodeScan = '';

    public function mount(): void
    {
        $this->addItem();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'payment_form' => ['required', 'string', 'max:2'],
            'payment_method' => ['required', 'in:PUE,PPD'],
            'cfdi_use' => ['required', 'string', 'max:4'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->whereNotNull('unit_price'),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function addItem(): void
    {
        $this->items[(string) Str::uuid()] = ['product_id' => null, 'quantity' => '1', 'search' => '', 'results' => []];
    }

    public function removeItem(string $rowId): void
    {
        unset($this->items[$rowId]);
    }

    public function updatedCustomerSearch(): void
    {
        $this->customerResults = $this->searchCustomers($this->customerSearch);
    }

    /**
     * Show a browsable list of customers as soon as the field is focused,
     * even before the user types anything (acts like a select).
     */
    public function openCustomerResults(): void
    {
        $this->customerResults = $this->searchCustomers($this->customerSearch);
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return;
        }

        $this->customer_id = $customer->id;
        $this->customerSearch = "{$customer->name} ({$customer->rfc})";
        $this->customerResults = [];
        $this->cfdi_use = $customer->cfdi_use;
    }

    /**
     * @return array<int, array{id: int, name: string, rfc: string}>
     */
    private function searchCustomers(string $term): array
    {
        $term = mb_strtolower(trim($term));

        return Customer::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('rfc', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'rfc'])
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'rfc' => $customer->rfc,
            ])
            ->all();
    }

    /**
     * Handle live updates for the per-row product search fields (items.{rowId}.search).
     */
    public function updated(string $property, mixed $value): void
    {
        if (! preg_match('/^items\.([^.]+)\.search$/', $property, $matches)) {
            return;
        }

        $this->items[$matches[1]]['results'] = $this->searchProducts((string) $value);
    }

    /**
     * Show a browsable list of products as soon as a row is focused,
     * even before the user types anything (acts like a select).
     */
    public function openItemResults(string $rowId): void
    {
        $this->items[$rowId]['results'] = $this->searchProducts($this->items[$rowId]['search']);
    }

    public function selectItemProduct(string $rowId, int $productId): void
    {
        $product = Product::find($productId);

        if (! $product) {
            return;
        }

        $this->items[$rowId]['product_id'] = $product->id;
        $this->items[$rowId]['search'] = "{$product->name} ({$product->sku})";
        $this->items[$rowId]['results'] = [];
    }

    /**
     * @return array<int, array{id: int, name: string, sku: string, unit_price: string}>
     */
    private function searchProducts(string $term): array
    {
        $term = mb_strtolower(trim($term));

        return Product::query()
            ->priced()
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'sku', 'unit_price'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => (string) $product->unit_price,
            ])
            ->all();
    }

    public function scanBarcode(): void
    {
        $code = trim($this->barcodeScan);
        $this->barcodeScan = '';

        if ($code === '') {
            return;
        }

        $product = Product::where('barcode', $code)->first();

        if (! $product) {
            Flux::toast(variant: 'danger', text: __('No se encontró ningún producto con ese código de barras.'));

            return;
        }

        if ($product->unit_price === null) {
            Flux::toast(variant: 'danger', text: __('Este producto aún no tiene precio definido, no se puede facturar: ').$product->name);

            return;
        }

        $this->addOrIncrementItem($product->id);

        Flux::toast(variant: 'success', text: __('Agregado: ').$product->name);
    }

    private function addOrIncrementItem(int $productId): void
    {
        foreach ($this->items as $rowId => $row) {
            if ((int) ($row['product_id'] ?? 0) === $productId) {
                $this->items[$rowId]['quantity'] = (string) ((float) $this->items[$rowId]['quantity'] + 1);

                return;
            }
        }

        $product = Product::find($productId);

        if (! $product) {
            return;
        }

        $label = "{$product->name} ({$product->sku})";

        foreach ($this->items as $rowId => $row) {
            if (empty($row['product_id'])) {
                $this->items[$rowId]['product_id'] = $productId;
                $this->items[$rowId]['quantity'] = '1';
                $this->items[$rowId]['search'] = $label;
                $this->items[$rowId]['results'] = [];

                return;
            }
        }

        $this->items[(string) Str::uuid()] = [
            'product_id' => $productId,
            'quantity' => '1',
            'search' => $label,
            'results' => [],
        ];
    }

    #[Computed]
    public function products()
    {
        return Product::query()->orderBy('name')->get();
    }

    /**
     * Live preview of each row's amounts, before saving.
     *
     * @return array<string, array{subtotal: float, tax: float, total: float}>
     */
    #[Computed]
    public function lineTotals(): array
    {
        $products = $this->products->keyBy('id');
        $rows = [];

        foreach ($this->items as $rowId => $row) {
            $product = $row['product_id'] ? $products->get((int) $row['product_id']) : null;
            $quantity = (float) ($row['quantity'] ?: 0);
            $subtotal = $product ? round($quantity * (float) $product->unit_price, 2) : 0.0;
            $tax = $product ? round($subtotal * (float) $product->tax_rate, 2) : 0.0;

            $rows[$rowId] = ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
        }

        return $rows;
    }

    /**
     * @return array{subtotal: float, tax: float, total: float}
     */
    #[Computed]
    public function grandTotal(): array
    {
        $subtotal = round(array_sum(array_column($this->lineTotals, 'subtotal')), 2);
        $tax = round(array_sum(array_column($this->lineTotals, 'tax')), 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
    }

    public function paymentFormLabel(): string
    {
        return match ($this->payment_form) {
            '01' => __('01 · Efectivo'),
            '02' => __('02 · Cheque nominativo'),
            '03' => __('03 · Transferencia electrónica'),
            '04' => __('04 · Tarjeta de crédito'),
            '28' => __('28 · Tarjeta de débito'),
            '99' => __('99 · Por definir'),
            default => $this->payment_form,
        };
    }

    public function cfdiUseLabel(): string
    {
        return match ($this->cfdi_use) {
            'G01' => __('G01 · Adquisición de mercancías'),
            'G03' => __('G03 · Gastos en general'),
            'P01' => __('P01 · Por definir'),
            default => $this->cfdi_use,
        };
    }

    /**
     * Validate everything and, if valid, open the confirmation summary
     * before actually creating and stamping the invoice.
     */
    public function reviewInvoice(): void
    {
        $this->validate();

        Flux::modal('invoice-confirmation')->show();
    }

    public function save(StampInvoice $stampInvoice): void
    {
        $validated = $this->validate();

        $products = Product::query()
            ->whereIn('id', collect($validated['items'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $invoice = DB::transaction(function () use ($validated, $products) {
            $invoice = Invoice::create([
                'customer_id' => $validated['customer_id'],
                'payment_form' => $validated['payment_form'],
                'payment_method' => $validated['payment_method'],
                'cfdi_use' => $validated['cfdi_use'],
                'currency' => 'MXN',
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($validated['items'] as $row) {
                $product = $products[$row['product_id']];
                $quantity = (float) $row['quantity'];
                $lineSubtotal = round($quantity * (float) $product->unit_price, 2);
                $lineTax = round($lineSubtotal * (float) $product->tax_rate, 2);

                $invoice->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->unit_price,
                    'tax_rate' => $product->tax_rate,
                    'subtotal' => $lineSubtotal,
                    'tax_amount' => $lineTax,
                    'total' => $lineSubtotal + $lineTax,
                ]);

                $subtotal += $lineSubtotal;
                $taxTotal += $lineTax;
            }

            $invoice->subtotal = $subtotal;
            $invoice->tax_total = $taxTotal;
            $invoice->total = $subtotal + $taxTotal;
            $invoice->save();

            return $invoice;
        });

        $invoice = $stampInvoice->handle($invoice);

        if ($invoice->status === InvoiceStatus::Stamped) {
            Flux::toast(variant: 'success', text: __('Factura timbrada correctamente.'));
        } else {
            Flux::toast(variant: 'danger', text: __('La factura se guardó como borrador, pero no se pudo timbrar: ').$invoice->error_message);
        }

        $this->redirect(route('invoices.index'), navigate: true);
    }
}; ?>

<div class="w-full">
    <flux:heading size="xl">{{ __('Nueva factura') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Selecciona el cliente y los productos a facturar.') }}</flux:subheading>

    <div class="space-y-8">
        <div class="relative">
            <flux:input
                wire:model.live.debounce.300ms="customerSearch"
                wire:focus="openCustomerResults"
                :label="__('Cliente')"
                placeholder="{{ __('Haz clic para ver todos, o escribe para buscar...') }}"
                onfocus="this.select()"
                autocomplete="off"
            />

            @if (count($customerResults) > 0)
                <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($customerResults as $result)
                        <button
                            type="button"
                            wire:click="selectCustomer({{ $result['id'] }})"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                        >
                            {{ $result['name'] }} <span class="text-zinc-500">({{ $result['rfc'] }})</span>
                        </button>
                    @endforeach
                </div>
            @endif

            @error('customer_id')
                <flux:text class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
            @enderror
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model="payment_form" :label="__('Forma de pago')" placeholder="{{ __('Selecciona una opción') }}">
                <flux:select.option value="01">{{ __('01 · Efectivo') }}</flux:select.option>
                <flux:select.option value="02">{{ __('02 · Cheque nominativo') }}</flux:select.option>
                <flux:select.option value="03">{{ __('03 · Transferencia electrónica') }}</flux:select.option>
                <flux:select.option value="04">{{ __('04 · Tarjeta de crédito') }}</flux:select.option>
                <flux:select.option value="28">{{ __('28 · Tarjeta de débito') }}</flux:select.option>
                <flux:select.option value="99">{{ __('99 · Por definir') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="payment_method" :label="__('Método de pago')" placeholder="{{ __('Selecciona una opción') }}">
                <flux:select.option value="PUE">{{ __('PUE · Pago en una exhibición') }}</flux:select.option>
                <flux:select.option value="PPD">{{ __('PPD · Pago en parcialidades') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="cfdi_use" :label="__('Uso de CFDI')" placeholder="{{ __('Selecciona una opción') }}">
                <flux:select.option value="G01">{{ __('G01 · Adquisición de mercancías') }}</flux:select.option>
                <flux:select.option value="G03">{{ __('G03 · Gastos en general') }}</flux:select.option>
                <flux:select.option value="P01">{{ __('P01 · Por definir') }}</flux:select.option>
            </flux:select>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Productos') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="addItem">{{ __('Agregar renglón') }}</flux:button>
            </div>

            <div class="mb-4">
                <form wire:submit.prevent="scanBarcode" class="max-w-md">
                    <flux:input
                        wire:model="barcodeScan"
                        icon="qr-code"
                        :label="__('Escanear código de barras')"
                        placeholder="{{ __('Apunta el escáner aquí y dispara') }}"
                        autocomplete="off"
                    />
                </form>
            </div>

            <div class="space-y-3">
                @foreach ($items as $rowId => $row)
                    <div wire:key="item-{{ $rowId }}" class="grid grid-cols-12 items-end gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="relative col-span-6">
                            <flux:input
                                wire:model.live.debounce.300ms="items.{{ $rowId }}.search"
                                wire:focus="openItemResults('{{ $rowId }}')"
                                :label="__('Producto')"
                                placeholder="{{ __('Haz clic para ver todos, o escribe para buscar...') }}"
                                onfocus="this.select()"
                                autocomplete="off"
                            />

                            @if (count($row['results'] ?? []) > 0)
                                <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                    @foreach ($row['results'] as $result)
                                        <button
                                            type="button"
                                            wire:click="selectItemProduct('{{ $rowId }}', {{ $result['id'] }})"
                                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                        >
                                            <span>{{ $result['name'] }} <span class="text-zinc-500">({{ $result['sku'] }})</span></span>
                                            <span class="font-medium">${{ number_format((float) $result['unit_price'], 2) }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="col-span-2">
                            <flux:input wire:model.live="items.{{ $rowId }}.quantity" :label="__('Cantidad')" type="number" step="0.01" min="0.01" />
                        </div>

                        <div class="col-span-3">
                            <flux:text class="mb-2 text-xs">{{ __('Total del renglón') }}</flux:text>
                            <flux:text class="font-medium">${{ number_format($this->lineTotals[$rowId]['total'] ?? 0, 2) }}</flux:text>
                        </div>

                        <div class="col-span-1 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem('{{ $rowId }}')" :disabled="count($items) <= 1" />
                        </div>
                    </div>
                @endforeach
            </div>

            @error('items')
                <flux:text class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
            @enderror
        </div>

        <div class="flex justify-end">
            <div class="w-full max-w-xs space-y-1">
                <div class="flex justify-between">
                    <flux:text>{{ __('Subtotal') }}</flux:text>
                    <flux:text>${{ number_format($this->grandTotal['subtotal'], 2) }}</flux:text>
                </div>
                <div class="flex justify-between">
                    <flux:text>{{ __('IVA') }}</flux:text>
                    <flux:text>${{ number_format($this->grandTotal['tax'], 2) }}</flux:text>
                </div>
                <div class="flex justify-between">
                    <flux:heading>{{ __('Total') }}</flux:heading>
                    <flux:heading>${{ number_format($this->grandTotal['total'], 2) }}</flux:heading>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('invoices.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
            <flux:button type="button" variant="primary" wire:click="reviewInvoice">{{ __('Revisar y timbrar') }}</flux:button>
        </div>
    </div>

    <flux:modal name="invoice-confirmation" class="md:w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirma tu factura') }}</flux:heading>
                <flux:subheading>{{ __('Revisa los datos antes de timbrarla ante el SAT. Esta acción no se puede deshacer.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-2 gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <flux:text class="text-xs text-zinc-500">{{ __('Cliente') }}</flux:text>
                    <flux:text class="font-medium">{{ $customerSearch ?: '—' }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs text-zinc-500">{{ __('Forma de pago') }}</flux:text>
                    <flux:text class="font-medium">{{ $this->paymentFormLabel() }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs text-zinc-500">{{ __('Método de pago') }}</flux:text>
                    <flux:text class="font-medium">{{ $payment_method }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs text-zinc-500">{{ __('Uso de CFDI') }}</flux:text>
                    <flux:text class="font-medium">{{ $this->cfdiUseLabel() }}</flux:text>
                </div>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Producto') }}</flux:table.column>
                    <flux:table.column>{{ __('Cantidad') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($items as $rowId => $row)
                        @if ($row['product_id'])
                            <flux:table.row :key="'confirm-'.$rowId">
                                <flux:table.cell>{{ $row['search'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['quantity'] }}</flux:table.cell>
                                <flux:table.cell align="end">${{ number_format($this->lineTotals[$rowId]['total'] ?? 0, 2) }}</flux:table.cell>
                            </flux:table.row>
                        @endif
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="flex justify-end">
                <div class="w-full max-w-xs space-y-1">
                    <div class="flex justify-between">
                        <flux:text>{{ __('Subtotal') }}</flux:text>
                        <flux:text>${{ number_format($this->grandTotal['subtotal'], 2) }}</flux:text>
                    </div>
                    <div class="flex justify-between">
                        <flux:text>{{ __('IVA') }}</flux:text>
                        <flux:text>${{ number_format($this->grandTotal['tax'], 2) }}</flux:text>
                    </div>
                    <div class="flex justify-between">
                        <flux:heading>{{ __('Total') }}</flux:heading>
                        <flux:heading>${{ number_format($this->grandTotal['total'], 2) }}</flux:heading>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Volver y editar') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="save">{{ __('Confirmar y timbrar') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\PaymentMethod;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMinimum;
use App\Models\Warehouse;
use App\Services\CashSessionService;
use App\Services\CustomerBalanceService;
use App\Services\SaleService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Punto de venta')] class extends Component {
    public string $search = '';

    /**
     * Cart lines keyed by product id.
     *
     * @var array<int, array{product_id: int, quantity: string, price_type: string}>
     */
    public array $lines = [];

    public string $notes = '';

    public ?int $customer_id = null;
    public ?int $customer_destination_id = null;
    public string $customer_search = '';

    public string $payment_method = 'cash';
    public string $received_amount = '';
    public string $reference = '';

    /** A notice that blocks adding a product (e.g. it has no price). */
    public ?string $notice = null;

    // Quick customer and new account (docs/diseno ClienteRapido, NuevaCuenta)
    public string $new_customer_name = '';
    public string $new_customer_phone = '';
    public bool $new_customer_credit = false;
    public string $new_destination_name = '';

    /** @var array{folio: string, customer: string, total: string, change: string|null}|null */
    public ?array $registeredSale = null;

    #[Computed]
    public function warehouse(): ?Warehouse
    {
        return Warehouse::query()->where('is_sellable', true)->where('is_active', true)->orderBy('id')->first();
    }

    #[Computed]
    public function openSession(): ?CashSession
    {
        return app(CashSessionService::class)->current();
    }

    /**
     * Products matching the search by name, internal code or barcode.
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $products = Product::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term))
            ->orderBy('name')
            ->limit(8)
            ->get();

        $available = $this->availableFor($products->modelKeys());

        return $products->each(fn (Product $product) => $product->setAttribute('available', $available[$product->id] ?? '0'));
    }

    /**
     * The products in the cart, keyed by id.
     */
    #[Computed]
    public function cartProducts(): Collection
    {
        return Product::query()->findMany(array_keys($this->lines))->keyBy('id');
    }

    /**
     * @return array<int, string> stock in the selling warehouse by product id.
     */
    #[Computed]
    public function stock(): array
    {
        return $this->availableFor(array_keys($this->lines));
    }

    /**
     * @return array<int, string> minimum stock in the selling warehouse by product id.
     */
    #[Computed]
    public function minimums(): array
    {
        return StockMinimum::query()
            ->where('warehouse_id', $this->warehouse?->id)
            ->whereIn('product_id', array_keys($this->lines))
            ->pluck('min_quantity', 'product_id')
            ->all();
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customer_id ? Customer::query()->with('destinations')->find($this->customer_id) : null;
    }

    #[Computed]
    public function customerResults(): Collection
    {
        $term = trim($this->customer_search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Customer::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('rfc', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function customerBalance(): ?string
    {
        return $this->customer?->hasCredit() ? app(CustomerBalanceService::class)->balance($this->customer) : null;
    }

    /**
     * Live prices and totals, with the same calculation the sale will get.
     *
     * @return array{lines: list<array<string, mixed>>, subtotal: string, discount_total: string, tax_total: string, ieps_total: string, total: string}|null
     */
    #[Computed]
    public function quote(): ?array
    {
        if ($this->lines === []) {
            return null;
        }

        try {
            return app(SaleService::class)->quote($this->items(), $this->customer);
        } catch (ValidationException) {
            return null;
        }
    }

    #[Computed]
    public function change(): ?string
    {
        if ($this->payment_method !== 'cash' || ! $this->quote || ! is_numeric($this->received_amount)) {
            return null;
        }

        return bcsub(bcadd($this->received_amount, '0', 2), $this->quote['total'], 2);
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->where('is_active', true)->findOrFail($productId);

        if ($product->unit_price === null) {
            $this->notice = __(':product no tiene precio público, así que no se puede vender.', ['product' => $product->name]);

            return;
        }

        $this->notice = null;

        if (isset($this->lines[$product->id])) {
            $this->lines[$product->id]['quantity'] = (string) ((float) $this->lines[$product->id]['quantity'] + 1);
        } else {
            $this->lines[$product->id] = ['product_id' => $product->id, 'quantity' => '1', 'price_type' => PriceType::Public->value];
        }

        $this->reset('search');
    }

    /**
     * Enter in the search box (or a barcode scanner): an exact barcode or code wins.
     */
    public function addFirstResult(): void
    {
        $term = trim($this->search);
        $exact = Product::query()->where('is_active', true)->where(fn (Builder $query) => $query->where('barcode', $term)->orWhere('sku', $term))->first();
        $product = $exact ?? $this->results->first();

        if ($product !== null) {
            $this->addProduct($product->id);
        }
    }

    public function removeLine(int $productId): void
    {
        unset($this->lines[$productId]);
    }

    public function selectCustomer(int $customerId): void
    {
        $this->customer_id = $customerId;
        $this->customer_destination_id = null;
        $this->reset('customer_search');

        if ($this->customer->destinations->count() === 1) {
            $this->customer_destination_id = $this->customer->destinations->first()->id;
        }
    }

    public function clearCustomer(): void
    {
        $this->reset('customer_id', 'customer_destination_id', 'customer_search');

        if ($this->payment_method === 'credit') {
            $this->payment_method = 'cash';
        }
    }

    public function createCustomer(): void
    {
        $validated = $this->validate([
            'new_customer_name' => ['required', 'string', 'max:255'],
            'new_customer_phone' => ['nullable', 'string', 'max:50'],
            'new_customer_credit' => ['boolean'],
        ]);

        $customer = Customer::create([
            'name' => $validated['new_customer_name'],
            'phone' => $validated['new_customer_phone'] ?: null,
            'credit_enabled' => $validated['new_customer_credit'],
        ]);

        $this->reset('new_customer_name', 'new_customer_phone', 'new_customer_credit');
        $this->selectCustomer($customer->id);
        Flux::modal('quick-customer')->close();
    }

    public function createDestination(): void
    {
        $validated = $this->validate(['new_destination_name' => ['required', 'string', 'max:255']]);

        $destination = $this->customer->destinations()->create(['name' => $validated['new_destination_name']]);

        $this->reset('new_destination_name');
        $this->customer_destination_id = $destination->id;
        unset($this->customer);
        Flux::modal('new-destination')->close();
    }

    public function charge(SaleService $sales): void
    {
        $total = $this->quote['total'] ?? null;

        if ($total === null || $this->warehouse === null) {
            return;
        }

        $sale = $sales->sell(
            Auth::user(),
            $this->warehouse,
            $this->items(),
            $this->payments($total),
            $this->customer,
            $this->customer_destination_id ? CustomerDestination::query()->findOrFail($this->customer_destination_id) : null,
            notes: $this->notes ?: null,
        );

        $cashPayment = $sale->payments()->where('method', PaymentMethod::Cash)->first();

        $this->registeredSale = [
            'folio' => $sale->folio,
            'customer' => collect([$this->customer?->name ?? __('Público en general'), $sale->customerDestination?->name])->filter()->join(' · '),
            'total' => $sale->total,
            'change' => $cashPayment?->change_amount,
        ];

        $this->newSale();
        Flux::modal('sale-registered')->show();
    }

    public function newSale(): void
    {
        $this->reset('search', 'lines', 'notes', 'customer_id', 'customer_destination_id', 'customer_search', 'payment_method', 'received_amount', 'reference', 'notice');
        $this->resetErrorBag();
    }

    /**
     * @return list<array{product_id: int, quantity: string, price_type: PriceType}>
     */
    private function items(): array
    {
        return array_values(array_map(fn (array $line): array => [
            'product_id' => $line['product_id'],
            'quantity' => (string) $line['quantity'],
            'price_type' => PriceType::from($line['price_type']),
        ], $this->lines));
    }

    /**
     * @return list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>
     */
    private function payments(string $total): array
    {
        return match ($this->payment_method) {
            'credit' => [],
            'cash' => [['method' => PaymentMethod::Cash, 'amount' => $total, 'received_amount' => $this->received_amount ?: null]],
            default => [['method' => PaymentMethod::from($this->payment_method), 'amount' => $total, 'reference' => $this->reference ?: null]],
        };
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    private function availableFor(array $productIds): array
    {
        if ($productIds === [] || $this->warehouse === null) {
            return [];
        }

        return Stock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as available')
            ->pluck('available', 'product_id')
            ->map(fn ($available) => (string) $available)
            ->all();
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
@endphp

<div class="mx-auto grid w-full max-w-7xl gap-6 lg:grid-cols-3">
    {{-- Cart --}}
    <div class="flex flex-col gap-4 lg:col-span-2">
        <div>
            <flux:heading size="xl">{{ __('Nueva venta') }}</flux:heading>
            <flux:text class="mt-1">
                {{ now()->translatedFormat('D j M Y') }}
                @if ($this->warehouse)
                    · {{ __('Sale de :warehouse', ['warehouse' => $this->warehouse->name]) }}
                @endif
            </flux:text>
        </div>

        @unless ($this->openSession)
            <flux:callout variant="warning" icon="lock-closed" :heading="__('La caja está cerrada')">
                <flux:callout.text>{{ __('Abre la caja para poder cobrar.') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('cash.index')" wire:navigate>{{ __('Abrir caja') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endunless

        <div class="relative">
            <flux:input
                wire:model.live.debounce.250ms="search"
                wire:keydown.enter.prevent="addFirstResult"
                icon="magnifying-glass"
                :placeholder="__('Agregar producto: nombre, código o código de barras')"
                autofocus
                autocomplete="off"
                data-test="product-search"
            />

            @if ($this->results->isNotEmpty())
                <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($this->results as $result)
                        <button
                            type="button"
                            wire:key="result-{{ $result->id }}"
                            wire:click="addProduct({{ $result->id }})"
                            class="flex w-full items-center justify-between gap-4 px-4 py-2 text-start hover:bg-surface dark:hover:bg-zinc-800"
                        >
                            <span>
                                <span class="font-medium">{{ $result->name }}</span>
                                <span class="block text-sm text-zinc-500">{{ $result->sku }}{{ $result->brand ? ' · '.$result->brand : '' }}</span>
                            </span>
                            <span class="text-end text-sm">
                                <span class="block">{{ $result->unit_price === null ? __('Sin precio') : $money($result->unit_price) }}</span>
                                <span class="block text-zinc-500">{{ __('Hay :quantity', ['quantity' => $qty($result->available)]) }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($notice)
            <flux:callout variant="danger" icon="no-symbol" :heading="$notice" />
        @endif

        <flux:card class="p-0">
            @if ($lines === [])
                <div class="p-8 text-center">
                    <flux:text>{{ __('Busca o escanea un producto para empezar la venta.') }}</flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Producto') }}</flux:table.column>
                        <flux:table.column>{{ __('Existencia') }}</flux:table.column>
                        <flux:table.column>{{ __('Cant.') }}</flux:table.column>
                        <flux:table.column>{{ __('Precio') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Importe') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @php
                            $priced = collect($this->quote['lines'] ?? [])->keyBy(fn ($line) => $line['product']->id);
                        @endphp
                        @foreach ($lines as $productId => $cartLine)
                            @php
                                $product = $this->cartProducts[$productId];
                                $line = $priced->get($productId);
                                $available = $this->stock[$productId] ?? '0';
                                $left = (float) $available - (float) $cartLine['quantity'];
                                $minimum = $this->minimums[$productId] ?? null;
                            @endphp
                            <flux:table.row :key="'line-'.$productId">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $product->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ $product->sku }}{{ $product->brand ? ' · '.$product->brand : '' }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="{{ $left < 0 ? 'font-medium text-red-700 dark:text-red-400' : '' }}">
                                        {{ $qty($available) }} → {{ __('quedan :left', ['left' => $qty(max($left, 0))]) }}
                                    </span>
                                    @if ($left < 0)
                                        <span class="block text-sm text-red-700 dark:text-red-400">{{ __('No alcanza') }}</span>
                                    @elseif ($minimum !== null && $left <= (float) $minimum)
                                        <span class="block text-sm text-amber-700 dark:text-amber-400">{{ __('bajo mínimo') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="w-24">
                                    <flux:input wire:model.live.debounce.400ms="lines.{{ $productId }}.quantity" type="number" step="any" min="0" size="sm" />
                                </flux:table.cell>
                                <flux:table.cell class="w-40">
                                    @if ($product->wholesale_price !== null)
                                        <flux:select wire:model.live="lines.{{ $productId }}.price_type" size="sm">
                                            <flux:select.option value="public">{{ $money($product->unit_price) }}</flux:select.option>
                                            <flux:select.option value="wholesale">{{ __('Mayoreo') }} {{ $money($product->wholesale_price) }}</flux:select.option>
                                        </flux:select>
                                    @else
                                        {{ $money($product->unit_price) }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $line ? $money(bcsub($line['subtotal'], $line['discount_amount'], 2)) : '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeLine({{ $productId }})" :aria-label="__('Quitar')" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>

        <flux:textarea wire:model="notes" :label="__('Observaciones (aparecen en la nota y en el historial)')" rows="2" />
    </div>

    {{-- Customer, totals and payment --}}
    <div class="flex flex-col gap-4">
        <flux:card class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <flux:heading>{{ __('Cliente') }}</flux:heading>
                <flux:modal.trigger name="quick-customer">
                    <flux:button size="sm" variant="ghost" icon="user-plus">{{ __('Dar de alta') }}</flux:button>
                </flux:modal.trigger>
            </div>

            @if ($this->customer)
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <flux:text class="font-medium text-ink dark:text-white">{{ $this->customer->name }}</flux:text>
                        <flux:text size="sm">
                            @if ($this->customer->hasCredit())
                                {{ __('Crédito · saldo :balance', ['balance' => $money($this->customerBalance)]) }}
                                @if ($this->customer->credit_limit !== null)
                                    · {{ __('límite :limit', ['limit' => $money($this->customer->credit_limit)]) }}
                                @endif
                            @else
                                {{ __('Contado') }}
                            @endif
                        </flux:text>
                        @if (bccomp($this->customer->discount_rate, '0', 4) === 1)
                            <flux:text size="sm">{{ __('Descuento fijo :rate%', ['rate' => rtrim(rtrim(bcmul($this->customer->discount_rate, '100', 2), '0'), '.')]) }}</flux:text>
                        @endif
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="clearCustomer">{{ __('Cambiar') }}</flux:button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($this->customer->destinations as $destination)
                        <flux:button
                            size="sm"
                            wire:key="destination-{{ $destination->id }}"
                            :variant="$customer_destination_id === $destination->id ? 'primary' : 'filled'"
                            wire:click="$set('customer_destination_id', {{ $destination->id }})"
                        >
                            {{ $destination->name }}
                        </flux:button>
                    @endforeach
                    <flux:modal.trigger name="new-destination">
                        <flux:button size="sm" variant="ghost" icon="plus">{{ __('Nueva cuenta') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            @else
                <div class="relative">
                    <flux:input wire:model.live.debounce.250ms="customer_search" icon="user" :placeholder="__('Público en general · buscar cliente')" autocomplete="off" />
                    @if ($this->customerResults->isNotEmpty())
                        <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->customerResults as $result)
                                <button type="button" wire:key="customer-{{ $result->id }}" wire:click="selectCustomer({{ $result->id }})" class="block w-full px-4 py-2 text-start hover:bg-surface dark:hover:bg-zinc-800">
                                    {{ $result->name }}
                                    <span class="text-sm text-zinc-500">{{ $result->hasCredit() ? __('Crédito') : __('Contado') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-3">
            <dl class="flex flex-col gap-2 text-sm">
                <div class="flex justify-between"><dt>{{ __('Subtotal') }}</dt><dd class="tabular-nums">{{ $money($this->quote['subtotal'] ?? 0) }}</dd></div>
                @if (($this->quote['discount_total'] ?? '0') > 0)
                    <div class="flex justify-between"><dt>{{ __('Descuento') }}</dt><dd class="tabular-nums">−{{ $money($this->quote['discount_total']) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt>{{ __('IVA / IEPS según producto') }}</dt><dd class="tabular-nums">{{ $money(bcadd($this->quote['tax_total'] ?? '0', $this->quote['ieps_total'] ?? '0', 2)) }}</dd></div>
                <div class="flex justify-between border-t border-line pt-2 text-lg font-semibold dark:border-zinc-700">
                    <dt>{{ __('Total') }}</dt>
                    <dd class="tabular-nums" data-test="sale-total">{{ $money($this->quote['total'] ?? 0) }}</dd>
                </div>
            </dl>

            <flux:radio.group wire:model.live="payment_method" :label="__('Forma de pago')" variant="segmented" size="sm">
                <flux:radio value="cash" :label="__('Efectivo')" />
                <flux:radio value="card" :label="__('Tarjeta')" />
                <flux:radio value="transfer" :label="__('Transferencia')" />
                @if ($this->customer?->hasCredit())
                    <flux:radio value="credit" :label="__('A crédito')" />
                @endif
            </flux:radio.group>

            @if ($payment_method === 'cash')
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model.live.debounce.300ms="received_amount" :label="__('Recibido')" type="number" step="0.01" min="0" icon="currency-dollar" />
                    <div>
                        <flux:label>{{ __('Cambio') }}</flux:label>
                        <flux:heading size="lg" class="mt-2 tabular-nums">{{ $this->change !== null && $this->change >= 0 ? $money($this->change) : '—' }}</flux:heading>
                    </div>
                </div>
            @elseif (in_array($payment_method, ['card', 'transfer'], true))
                <flux:input wire:model="reference" :label="__('Referencia')" :placeholder="__('Autorización o número de transferencia')" />
            @endif

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    @foreach ($errors->all() as $message)
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    @endforeach
                </flux:callout>
            @endif

            <flux:button
                variant="primary"
                wire:click="charge"
                :disabled="! $this->quote || ! $this->openSession"
                data-test="charge-button"
            >
                {{ __('Cobrar') }} {{ $this->quote ? $money($this->quote['total']) : '' }}
            </flux:button>
        </flux:card>
    </div>

    {{-- docs/diseno/ClienteRapido.html --}}
    <flux:modal name="quick-customer" class="md:w-96">
        <form wire:submit="createCustomer" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Alta rápida de cliente') }}</flux:heading>
            <flux:input wire:model="new_customer_name" :label="__('Nombre')" required />
            <flux:input wire:model="new_customer_phone" :label="__('Teléfono')" />
            <flux:checkbox wire:model="new_customer_credit" :label="__('Cliente de crédito (sin límite)')" />
            <flux:text size="sm">{{ __('Los datos fiscales y el límite se capturan después en Clientes.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Guardar y usar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- docs/diseno/NuevaCuenta.html --}}
    <flux:modal name="new-destination" class="md:w-96">
        <form wire:submit="createDestination" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Nueva cuenta o destino') }}</flux:heading>
            <flux:input wire:model="new_destination_name" :label="__('Nombre')" :placeholder="__('Rancho, ciudad…')" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Agregar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- docs/diseno/VentaRegistrada.html --}}
    <flux:modal name="sale-registered" class="md:w-96">
        @if ($registeredSale)
            <div class="flex flex-col gap-4 text-center">
                <flux:icon.check-circle class="mx-auto size-12 text-accent" />
                <flux:heading size="lg">{{ __('Venta :folio registrada', ['folio' => $registeredSale['folio']]) }}</flux:heading>
                <flux:text>{{ $registeredSale['customer'] }} · {{ __('total :total', ['total' => $money($registeredSale['total'])]) }}</flux:text>
                @if ($registeredSale['change'] !== null && $registeredSale['change'] > 0)
                    <div>
                        <flux:text>{{ __('Entrega de cambio') }}</flux:text>
                        <flux:heading size="xl" class="tabular-nums">{{ $money($registeredSale['change']) }}</flux:heading>
                    </div>
                @endif
                <flux:modal.close>
                    <flux:button variant="primary" class="w-full">{{ __('Nueva venta') }}</flux:button>
                </flux:modal.close>
            </div>
        @endif
    </flux:modal>
</div>

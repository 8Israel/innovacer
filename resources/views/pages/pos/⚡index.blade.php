<?php

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\PaymentMethod;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\Stock;
use App\Models\StockMinimum;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\CashSessionService;
use App\Services\CustomerBalanceService;
use App\Services\SaleAuthorization;
use App\Services\SaleService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /** Extra discount for this sale, in percent; it needs an authorization. */
    public string $extra_discount = '';

    /**
     * Mixed payment rows (docs/diseno/PagoMixto.html).
     *
     * @var list<array{method: string, amount: string, received_amount: string, reference: string}>
     */
    public array $split_payments = [];

    public bool $leave_rest_on_credit = false;

    // Authorization (docs/diseno/Autorizacion.html)
    public ?int $authorizer_id = null;
    public string $authorizer_password = '';
    public string $authorization_note = '';
    public ?string $authorization_needed_for = null;
    public bool $authorization_owner_only = false;

    /**
     * Payments of the charge waiting for an authorization.
     *
     * @var list<array{method: string, amount: string, received_amount: string|null, reference: string|null}>
     */
    public array $charge_payments = [];

    /** The pending sale loaded into the cart, replaced when it is charged or held again. */
    public ?int $resuming_sale_id = null;

    public string $hold_note = '';

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
            return app(SaleService::class)->quote($this->items(), $this->customer, $this->discountRate());
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Sales held to be resumed later (docs/diseno/VentasPendientes.html).
     */
    #[Computed]
    public function pendingSales(): Collection
    {
        return Sale::query()
            ->where('status', SaleStatus::Pending)
            ->with('customer')
            ->withCount('items')
            ->latest('held_at')
            ->get();
    }

    /**
     * Users who may approve what this charge needs: anyone active for an extra
     * discount, only owners for credit over the limit.
     */
    #[Computed]
    public function authorizers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($this->authorization_owner_only, fn (Builder $query) => $query->where('role', UserRole::Owner))
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    /**
     * Totals of the mixed payment rows: paid, missing and change.
     *
     * @return array{paid: string, missing: string, change: string}
     */
    #[Computed]
    public function splitSummary(): array
    {
        $paid = '0.00';
        $change = '0.00';

        foreach ($this->split_payments as $row) {
            $amount = is_numeric($row['amount']) ? bcadd($row['amount'], '0', 2) : '0.00';
            $paid = bcadd($paid, $amount, 2);

            if ($row['method'] === 'cash' && is_numeric($row['received_amount'])) {
                $rowChange = bcsub(bcadd($row['received_amount'], '0', 2), $amount, 2);
                $change = bccomp($rowChange, '0', 2) === 1 ? bcadd($change, $rowChange, 2) : $change;
            }
        }

        $missing = bcsub($this->quote['total'] ?? '0', $paid, 2);

        return ['paid' => $paid, 'missing' => bccomp($missing, '0', 2) > 0 ? $missing : '0.00', 'change' => $change];
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

    /**
     * Charge with the single payment method chosen on screen.
     */
    public function charge(): void
    {
        $total = $this->quote['total'] ?? null;

        if ($total === null) {
            return;
        }

        $this->attemptCharge(match ($this->payment_method) {
            'credit' => [],
            'cash' => [['method' => 'cash', 'amount' => $total, 'received_amount' => $this->received_amount ?: null, 'reference' => null]],
            default => [['method' => $this->payment_method, 'amount' => $total, 'received_amount' => null, 'reference' => $this->reference ?: null]],
        });
    }

    public function openSplitPayment(): void
    {
        $this->split_payments = [['method' => 'cash', 'amount' => $this->quote['total'] ?? '', 'received_amount' => '', 'reference' => '']];
        $this->leave_rest_on_credit = false;
        $this->resetErrorBag();
        Flux::modal('split-payment')->show();
    }

    public function addSplitRow(): void
    {
        $this->split_payments[] = ['method' => 'transfer', 'amount' => $this->splitSummary['missing'], 'received_amount' => '', 'reference' => ''];
    }

    public function removeSplitRow(int $index): void
    {
        unset($this->split_payments[$index]);
        $this->split_payments = array_values($this->split_payments);
    }

    /**
     * Charge with several payment methods; what they leave unpaid goes on credit
     * only when that was chosen explicitly.
     */
    public function chargeSplit(): void
    {
        if (bccomp($this->splitSummary['missing'], '0', 2) === 1 && ! $this->leave_rest_on_credit) {
            throw ValidationException::withMessages([
                'payments' => __('Faltan :missing por cobrar. Agrega otra forma de pago o déjalo a crédito.', ['missing' => '$'.number_format((float) $this->splitSummary['missing'], 2)]),
            ]);
        }

        $payments = collect($this->split_payments)
            ->filter(fn (array $row) => is_numeric($row['amount']) && $row['amount'] > 0)
            ->map(fn (array $row) => [
                'method' => $row['method'],
                'amount' => $row['amount'],
                'received_amount' => $row['method'] === 'cash' && is_numeric($row['received_amount']) ? $row['received_amount'] : null,
                'reference' => $row['method'] === 'cash' ? null : ($row['reference'] ?: null),
            ])
            ->values()
            ->all();

        Flux::modal('split-payment')->close();
        $this->attemptCharge($payments);
    }

    /**
     * Approve what the charge needed (extra discount or credit over the limit) and charge it.
     */
    public function authorizeCharge(): void
    {
        $this->validate(['authorizer_id' => ['required', 'integer']]);

        $authorization = new SaleAuthorization(
            User::query()->where('is_active', true)->findOrFail($this->authorizer_id),
            $this->authorizer_password,
            $this->authorization_note ?: null,
        );

        $this->reset('authorizer_password');
        $this->attemptCharge($this->charge_payments, $authorization);
    }

    /**
     * Save the cart as a pending sale to resume later; it does not touch stock or cash.
     */
    public function holdSale(SaleService $sales): void
    {
        if ($this->lines === [] || $this->warehouse === null) {
            return;
        }

        DB::transaction(function () use ($sales): void {
            $this->discardResumedSale($sales);

            $sales->hold(
                Auth::user(),
                $this->warehouse,
                $this->items(),
                $this->customer,
                $this->destination(),
                $this->discountRate(),
                $this->notes ?: null,
                $this->hold_note ?: null,
            );
        });

        Flux::modal('hold-sale')->close();
        $this->newSale();
        unset($this->pendingSales);
        Flux::toast(variant: 'success', text: __('Venta guardada como pendiente.'));
    }

    /**
     * Load a pending sale into the cart. It is replaced when charged or held again;
     * prices and stock are checked again then.
     */
    public function resumeSale(int $saleId): void
    {
        $sale = Sale::query()->where('status', SaleStatus::Pending)->with('items')->findOrFail($saleId);

        $this->newSale();
        $this->resuming_sale_id = $sale->id;
        $this->customer_id = $sale->customer_id;
        $this->customer_destination_id = $sale->customer_destination_id;
        $this->notes = (string) $sale->notes;
        $this->extra_discount = bccomp($sale->discount_rate, '0', 4) === 1 ? rtrim(rtrim(bcmul($sale->discount_rate, '100', 2), '0'), '.') : '';
        $this->hold_note = (string) $sale->hold_note;

        foreach ($sale->items as $item) {
            $this->lines[$item->product_id] = [
                'product_id' => $item->product_id,
                'quantity' => rtrim(rtrim($item->quantity, '0'), '.'),
                'price_type' => $item->price_type->value,
            ];
        }

        Flux::modal('pending-sales')->close();
    }

    public function discardPendingSale(int $saleId, SaleService $sales): void
    {
        $sales->discard(Sale::query()->findOrFail($saleId));

        if ($this->resuming_sale_id === $saleId) {
            $this->resuming_sale_id = null;
        }

        unset($this->pendingSales);
    }

    public function newSale(): void
    {
        $this->reset(
            'search', 'lines', 'notes', 'customer_id', 'customer_destination_id', 'customer_search', 'payment_method',
            'received_amount', 'reference', 'notice', 'extra_discount', 'split_payments', 'leave_rest_on_credit',
            'charge_payments', 'authorizer_id', 'authorizer_password', 'authorization_note', 'authorization_needed_for',
            'authorization_owner_only', 'resuming_sale_id', 'hold_note',
        );
        $this->resetErrorBag();
    }

    /**
     * Ring up the cart, first asking for an authorization when the sale needs one.
     *
     * @param  list<array{method: string, amount: string, received_amount: string|null, reference: string|null}>  $payments
     */
    private function attemptCharge(array $payments, ?SaleAuthorization $authorization = null): void
    {
        if ($this->warehouse === null || $this->quote === null) {
            return;
        }

        if ($authorization === null && ($neededFor = $this->authorizationNeededFor($payments)) !== null) {
            $this->charge_payments = $payments;
            $this->authorization_needed_for = $neededFor;
            $this->authorization_owner_only = $this->isOverCreditLimit($payments);
            $this->reset('authorizer_id', 'authorizer_password', 'authorization_note');
            unset($this->authorizers);
            Flux::modal('authorization')->show();

            return;
        }

        $sales = app(SaleService::class);

        $sale = DB::transaction(function () use ($sales, $payments, $authorization) {
            $this->discardResumedSale($sales);

            return $sales->sell(
                Auth::user(),
                $this->warehouse,
                $this->items(),
                $this->toPayments($payments),
                $this->customer,
                $this->destination(),
                $this->discountRate(),
                $this->notes ?: null,
                $authorization,
            );
        });

        $this->registeredSale = [
            'folio' => $sale->folio,
            'customer' => collect([$this->customer?->name ?? __('Público en general'), $sale->customerDestination?->name])->filter()->join(' · '),
            'total' => $sale->total,
            'change' => $this->decimalSum($sale->payments()->where('method', PaymentMethod::Cash)->sum('change_amount')),
        ];

        Flux::modal('authorization')->close();
        $this->newSale();
        unset($this->pendingSales);
        Flux::modal('sale-registered')->show();
    }

    /**
     * Why this charge needs someone's approval, or null when it does not.
     *
     * @param  list<array{method: string, amount: string, received_amount: string|null, reference: string|null}>  $payments
     */
    private function authorizationNeededFor(array $payments): ?string
    {
        $reasons = [];

        if (bccomp($this->discountRate(), '0', 4) === 1) {
            $reasons[] = __('Descuento extra de :rate% en la venta', ['rate' => $this->extra_discount]);
        }

        if ($this->isOverCreditLimit($payments)) {
            $reasons[] = __(':customer rebasa su límite de crédito', ['customer' => $this->customer->name]);
        }

        return $reasons === [] ? null : implode(' · ', $reasons);
    }

    /**
     * Mirrors the sale's credit check, to ask for the owner's approval up front.
     *
     * @param  list<array{method: string, amount: string, received_amount: string|null, reference: string|null}>  $payments
     */
    private function isOverCreditLimit(array $payments): bool
    {
        if (! $this->customer?->hasCredit() || $this->customer->credit_limit === null) {
            return false;
        }

        $paid = array_reduce($payments, fn (string $carry, array $payment) => bcadd($carry, $payment['amount'], 2), '0');
        $onCredit = bcsub($this->quote['total'], $paid, 2);

        if (bccomp($onCredit, '0', 2) <= 0) {
            return false;
        }

        $balanceAfterSale = bcadd(app(CustomerBalanceService::class)->balance($this->customer), $onCredit, 2);

        return bccomp($balanceAfterSale, $this->customer->credit_limit, 2) === 1;
    }

    private function discardResumedSale(SaleService $sales): void
    {
        $pending = $this->resuming_sale_id
            ? Sale::query()->where('status', SaleStatus::Pending)->find($this->resuming_sale_id)
            : null;

        if ($pending !== null) {
            $sales->discard($pending);
        }
    }

    private function destination(): ?CustomerDestination
    {
        return $this->customer_destination_id ? CustomerDestination::query()->findOrFail($this->customer_destination_id) : null;
    }

    /**
     * The extra discount as a fraction (5 → 0.0500).
     */
    private function discountRate(): string
    {
        return is_numeric($this->extra_discount) && $this->extra_discount > 0
            ? bcdiv((string) $this->extra_discount, '100', 4)
            : '0';
    }

    /**
     * @param  list<array{method: string, amount: string, received_amount: string|null, reference: string|null}>  $payments
     * @return list<array{method: PaymentMethod, amount: string, received_amount: string|null, reference: string|null}>
     */
    private function toPayments(array $payments): array
    {
        return array_map(fn (array $payment): array => [...$payment, 'method' => PaymentMethod::from($payment['method'])], $payments);
    }

    private function decimalSum(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
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
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="xl">{{ $resuming_sale_id ? __('Venta pendiente') : __('Nueva venta') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ now()->translatedFormat('D j M Y') }}
                    @if ($this->warehouse)
                        · {{ __('Sale de :warehouse', ['warehouse' => $this->warehouse->name]) }}
                    @endif
                </flux:text>
            </div>
            <div class="flex gap-2">
                @if ($lines !== [] || $resuming_sale_id)
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="newSale">{{ __('Nueva venta') }}</flux:button>
                @endif
                <flux:modal.trigger name="pending-sales">
                    <flux:button size="sm" icon="clock" data-test="pending-sales-button">
                        {{ __('Pendientes') }}
                        @if ($this->pendingSales->isNotEmpty())
                            <flux:badge size="sm" color="amber" class="ms-1">{{ $this->pendingSales->count() }}</flux:badge>
                        @endif
                    </flux:button>
                </flux:modal.trigger>
            </div>
        </div>

        @if ($resuming_sale_id)
            <flux:callout icon="arrow-path" :heading="__('Retomaste una venta pendiente')">
                <flux:callout.text>{{ __('Al cobrar se revisan otra vez la existencia y los precios.') }}</flux:callout.text>
            </flux:callout>
        @endif

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
                <div class="flex items-center justify-between gap-3">
                    <dt>{{ __('Descuento extra') }}</dt>
                    <dd class="w-28">
                        <flux:input wire:model.live.debounce.400ms="extra_discount" type="number" step="0.5" min="0" max="99" size="sm" icon-trailing="percent-badge" :placeholder="__('0')" />
                    </dd>
                </div>
                @if (is_numeric($extra_discount) && $extra_discount > 0)
                    <flux:text size="sm">{{ __('Pide autorización al cobrar.') }}</flux:text>
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

            <div class="grid grid-cols-2 gap-2">
                <flux:button size="sm" wire:click="openSplitPayment" :disabled="! $this->quote || ! $this->openSession">
                    {{ __('Pagar con varias formas') }}
                </flux:button>
                <flux:modal.trigger name="hold-sale">
                    <flux:button size="sm" :disabled="! $this->quote">{{ __('Dejar pendiente') }}</flux:button>
                </flux:modal.trigger>
            </div>
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

    {{-- docs/diseno/PagoMixto.html --}}
    <flux:modal name="split-payment" class="md:w-[36rem]">
        <form wire:submit="chargeSplit" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Pagar con varias formas') }}</flux:heading>
                <flux:text>{{ __('Total :total', ['total' => $money($this->quote['total'] ?? 0)]) }}</flux:text>
            </div>

            @foreach ($split_payments as $index => $row)
                <div wire:key="split-{{ $index }}" class="grid grid-cols-12 items-end gap-2">
                    <div class="col-span-4">
                        <flux:select wire:model.live="split_payments.{{ $index }}.method" :label="__('Forma')" size="sm">
                            <flux:select.option value="cash">{{ __('Efectivo') }}</flux:select.option>
                            <flux:select.option value="card">{{ __('Tarjeta') }}</flux:select.option>
                            <flux:select.option value="transfer">{{ __('Transferencia') }}</flux:select.option>
                        </flux:select>
                    </div>
                    <div class="col-span-3">
                        <flux:input wire:model.live.debounce.300ms="split_payments.{{ $index }}.amount" :label="__('Monto')" type="number" step="0.01" min="0" size="sm" />
                    </div>
                    <div class="col-span-4">
                        @if ($row['method'] === 'cash')
                            <flux:input wire:model.live.debounce.300ms="split_payments.{{ $index }}.received_amount" :label="__('Recibido')" type="number" step="0.01" min="0" size="sm" />
                        @else
                            <flux:input wire:model="split_payments.{{ $index }}.reference" :label="__('Referencia')" size="sm" />
                        @endif
                    </div>
                    <div class="col-span-1">
                        @if (count($split_payments) > 1)
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeSplitRow({{ $index }})" :aria-label="__('Quitar')" />
                        @endif
                    </div>
                </div>
            @endforeach

            <div>
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSplitRow">{{ __('Agregar otra forma') }}</flux:button>
            </div>

            @if ($this->customer?->hasCredit())
                <flux:checkbox wire:model.live="leave_rest_on_credit" :label="__('Dejar lo que falte a crédito en su cuenta')" />
            @endif

            <dl class="grid grid-cols-3 gap-2 text-center">
                <div><dt class="text-sm">{{ __('Pagado') }}</dt><dd class="font-semibold tabular-nums">{{ $money($this->splitSummary['paid']) }}</dd></div>
                <div><dt class="text-sm">{{ __('Falta') }}</dt><dd class="font-semibold tabular-nums">{{ $money($this->splitSummary['missing']) }}</dd></div>
                <div><dt class="text-sm">{{ __('Cambio') }}</dt><dd class="font-semibold tabular-nums">{{ $money($this->splitSummary['change']) }}</dd></div>
            </dl>

            <flux:error name="payments" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="split-charge-button">{{ __('Cobrar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- docs/diseno/Autorizacion.html --}}
    <flux:modal name="authorization" class="md:w-[28rem]">
        <form wire:submit="authorizeCharge" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ $authorization_owner_only ? __('Necesita autorización del dueño') : __('Necesita autorización') }}</flux:heading>
                <flux:text>{{ $authorization_needed_for }}</flux:text>
            </div>

            <flux:select wire:model="authorizer_id" :label="__('Usuario que autoriza')" :placeholder="__('Elige…')">
                @foreach ($this->authorizers as $authorizer)
                    <flux:select.option :value="$authorizer->id">{{ $authorizer->name }} · {{ $authorizer->role->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="authorizer_password" :label="__('Contraseña')" type="password" autocomplete="off" viewable />
            <flux:input wire:model="authorization_note" :label="__('Motivo')" />
            <flux:text size="sm">{{ __('Queda registrado en la venta quién lo autorizó y por qué.') }}</flux:text>

            <flux:error name="authorization" />
            <flux:error name="authorizer_id" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="authorize-button">{{ __('Autorizar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hold-sale" class="md:w-96">
        <form wire:submit="holdSale" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Dejar pendiente') }}</flux:heading>
            <flux:text>{{ __('No descuenta inventario ni entra a la caja hasta que se cobre.') }}</flux:text>
            <flux:input wire:model="hold_note" :label="__('Nota')" :placeholder="__('Ej. Viene por ellas a las 4')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="hold-sale-button">{{ __('Guardar pendiente') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- docs/diseno/VentasPendientes.html --}}
    <flux:modal name="pending-sales" class="md:w-[36rem]">
        <div class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Ventas pendientes') }}</flux:heading>
                <flux:text>{{ __('No descuentan inventario hasta que se cobran.') }}</flux:text>
            </div>

            @forelse ($this->pendingSales as $pending)
                <div wire:key="pending-{{ $pending->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-line p-3 dark:border-zinc-700">
                    <div>
                        <flux:text class="font-medium text-ink dark:text-white">{{ $pending->customer?->name ?? __('Público en general') }}</flux:text>
                        <flux:text size="sm">
                            {{ trans_choice(':count producto|:count productos', $pending->items_count) }} · {{ $pending->held_at?->diffForHumans() }}
                            @if ($pending->hold_note)
                                · "{{ $pending->hold_note }}"
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="font-semibold tabular-nums">{{ $money($pending->total) }}</span>
                        <flux:button size="sm" variant="primary" wire:click="resumeSale({{ $pending->id }})">{{ __('Retomar') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="discardPendingSale({{ $pending->id }})" wire:confirm="{{ __('¿Descartar esta venta pendiente?') }}" :aria-label="__('Descartar')" />
                    </div>
                </div>
            @empty
                <flux:text>{{ __('No hay ventas pendientes.') }}</flux:text>
            @endforelse

            <flux:text size="sm">{{ __('Al retomar, el sistema revisa otra vez la existencia y los precios, por si cambiaron.') }}</flux:text>
        </div>
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

<?php

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierSettlementType;
use App\Services\SupplierDebtService;
use App\Services\SupplierPaymentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Proveedor')] class extends Component {
    public Supplier $supplier;

    public string $amount = '';

    public string $method = 'transfer';

    public string $reference = '';

    public string $note = '';

    /** 'auto' settles the oldest debt first; 'manual' uses the amounts per product. */
    public string $spread = 'auto';

    /** @var array<int, string> amount by product id. */
    public array $product_amounts = [];

    public string $name = '';

    public string $settlement_type = '';

    public string $phone = '';

    public string $email = '';

    public string $rfc = '';

    public string $notes = '';

    public bool $is_active = true;

    public function mount(Supplier $supplier): void
    {
        $this->supplier = $supplier;
    }

    #[Computed]
    public function isInKind(): bool
    {
        return $this->supplier->settlement_type === SupplierSettlementType::InKind;
    }

    /**
     * @return Collection<int, array{product: \App\Models\Product, unpaid_quantity: string, unit_costs: list<string>, pending: string}>
     */
    #[Computed]
    public function products(): Collection
    {
        return app(SupplierDebtService::class)->products($this->supplier);
    }

    #[Computed]
    public function pending(): string
    {
        return $this->products->reduce(fn (string $carry, array $row) => bcadd($carry, $row['pending'], 2), '0.00');
    }

    /**
     * @return array{value: string, quantity: string}
     */
    #[Computed]
    public function unsold(): array
    {
        return app(SupplierDebtService::class)->unsold($this->supplier);
    }

    /**
     * @return Collection<int, SupplierPayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->supplier->payments()->with('allocations.lot.product')->latest('paid_at')->latest('id')->get();
    }

    /**
     * What the payment being typed would cover, by product id, or the reason it cannot be made.
     *
     * @return array{by_product: array<int, string>, remaining: string, error: string|null}
     */
    #[Computed]
    public function preview(): array
    {
        $empty = ['by_product' => [], 'remaining' => $this->pending, 'error' => null];
        $amount = $this->spread === 'manual'
            ? array_reduce($this->product_amounts, fn (string $carry, $value) => is_numeric($value) && $value > 0 ? bcadd($carry, (string) $value, 2) : $carry, '0')
            : (is_numeric($this->amount) ? bcadd($this->amount, '0', 2) : '0');

        if (bccomp($amount, '0', 2) <= 0) {
            return $empty;
        }

        try {
            $plan = app(SupplierDebtService::class)->plan($this->supplier, $amount, $this->spread === 'manual' ? $this->product_amounts : null);
        } catch (ValidationException $exception) {
            return [...$empty, 'error' => collect($exception->errors())->flatten()->first()];
        }

        $byProduct = [];

        foreach ($plan as $allocation) {
            $productId = $allocation['lot']->product_id;
            $byProduct[$productId] = bcadd($byProduct[$productId] ?? '0', $allocation['amount'], 2);
        }

        return ['by_product' => $byProduct, 'remaining' => bcsub($this->pending, $amount, 2), 'error' => null];
    }

    public function updatedProductAmounts(): void
    {
        $this->amount = (string) array_reduce($this->product_amounts, fn (string $carry, $value) => is_numeric($value) && $value > 0 ? bcadd($carry, (string) $value, 2) : $carry, '0');
    }

    public function registerPayment(SupplierPaymentService $payments): void
    {
        Gate::authorize(Permission::PaySuppliers->value);

        $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'spread' => ['required', 'in:auto,manual'],
        ]);

        $payment = $payments->pay(
            auth()->user(),
            $this->supplier,
            PaymentMethod::from($this->method),
            (string) $this->amount,
            $this->reference ?: null,
            $this->note ?: null,
            $this->spread === 'manual' ? $this->product_amounts : null,
        );

        $this->reset('amount', 'reference', 'note', 'spread', 'product_amounts');
        unset($this->products, $this->pending, $this->payments, $this->preview);

        Flux::toast(variant: 'success', text: __('Pago de $:amount registrado.', ['amount' => number_format((float) $payment->amount, 2)]));
    }

    public function editSupplier(): void
    {
        $this->is_active = $this->supplier->is_active;
        $this->name = $this->supplier->name;
        $this->phone = (string) $this->supplier->phone;
        $this->email = (string) $this->supplier->email;
        $this->rfc = (string) $this->supplier->rfc;
        $this->notes = (string) $this->supplier->notes;
        $this->settlement_type = $this->supplier->settlement_type->value;
        $this->resetErrorBag();

        Flux::modal('edit-supplier')->show();
    }

    /**
     * The way of settling can only change while nothing is owed, so past debts keep their meaning.
     */
    public function saveSupplier(): void
    {
        $this->rfc = mb_strtoupper(trim($this->rfc));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($this->supplier->id)],
            'settlement_type' => ['required', Rule::enum(SupplierSettlementType::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ], ['rfc.regex' => __('El RFC no tiene el formato correcto.')]);

        $changesType = $validated['settlement_type'] !== $this->supplier->settlement_type->value;

        if ($changesType && ($this->supplier->lots()->exists() || $this->supplier->lentWarehouses()->exists())) {
            throw ValidationException::withMessages(['settlement_type' => __('Ya tiene producto registrado; su forma de trabajo ya no se puede cambiar.')]);
        }

        $this->supplier->update(array_map(fn ($value) => is_string($value) && blank($value) ? null : $value, $validated));
        unset($this->isInKind);
        Flux::modal('edit-supplier')->close();
        Flux::toast(variant: 'success', text: __('Proveedor actualizado.'));
    }

    /**
     * A quantity without trailing zeros: 40.000 → 40, 8.036 → 8.036.
     */
    public function format(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $lastPayment = $this->payments->first();
    $paidThisYear = $this->payments->filter(fn ($payment) => $payment->paid_at->isCurrentYear());
    $warehouses = $supplier->lentWarehouses()->pluck('name');
    $preview = $this->preview;
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div>
        <flux:link :href="route('suppliers.index')" wire:navigate class="text-sm">← {{ __('Proveedores') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl">{{ $supplier->name }}</flux:heading>
                    @unless ($supplier->is_active)
                        <flux:badge size="sm" color="zinc">{{ __('Inactivo') }}</flux:badge>
                    @endunless
                </div>
                <flux:text class="mt-1">
                    {{ $supplier->settlement_type->label() }} · {{ $supplier->settlement_type->description() }}
                    @if ($warehouses->isNotEmpty())
                        · {{ __('Su producto está en :warehouses', ['warehouses' => $warehouses->join(', ')]) }}
                    @endif
                </flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" icon="pencil-square" wire:click="editSupplier">{{ __('Editar proveedor') }}</flux:button>
                @can(\App\Models\Permission::ManageInventory->value)
                    <flux:button size="sm" icon="arrow-down-tray" :href="route('inventory.entry')" wire:navigate>{{ __('Registrar entrada') }}</flux:button>
                @endcan
            </div>
        </div>
    </div>

    @if ($this->isInKind)
        <flux:callout icon="arrow-path" :heading="__('A :supplier se le repone el mismo producto', ['supplier' => $supplier->name])">
            <flux:callout.text>
                {{ __('No se le paga en dinero. Lo que se le debe en producto está en') }}
                <flux:link :href="route('inventory.loans')" wire:navigate>{{ __('Préstamos') }}</flux:link>.
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm">{{ __('Por pagar (ya vendido)') }}</flux:text>
                <flux:heading size="lg">{{ $money($this->pending) }}</flux:heading>
                <flux:text size="sm">{{ $lastPayment ? __('Desde el último pago, :date', ['date' => $lastPayment->paid_at->translatedFormat('j M')]) : __('Sin pagos registrados') }}</flux:text>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm">{{ __('Producto suyo sin vender') }}</flux:text>
                <flux:heading size="lg">{{ $money($this->unsold['value']) }}</flux:heading>
                <flux:text size="sm">{{ __(':pieces piezas a costo · no se debe todavía', ['pieces' => $this->format($this->unsold['quantity'])]) }}</flux:text>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm">{{ __('Pagado en :year', ['year' => now()->year]) }}</flux:text>
                <flux:heading size="lg">{{ $money($paidThisYear->sum('amount')) }}</flux:heading>
                <flux:text size="sm">{{ trans_choice(':count pago registrado|:count pagos registrados', $paidThisYear->count()) }}</flux:text>
            </flux:card>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <flux:card class="flex flex-col gap-3 p-0">
                    <flux:heading class="px-4 pt-4">{{ __('Vendido pendiente de pago') }}</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Producto') }}</flux:table.column>
                            <flux:table.column align="end">{{ $supplier->settlement_type === \App\Models\SupplierSettlementType::Purchase ? __('Piezas') : __('Vendidas') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Costo') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Por pagar') }}</flux:table.column>
                            <flux:table.column>{{ __('Con este pago') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->products as $row)
                                @php
                                    $covered = $preview['by_product'][$row['product']->id] ?? null;
                                    $left = $covered ? bcsub($row['pending'], $covered, 2) : null;
                                @endphp
                                <flux:table.row :key="$row['product']->id">
                                    <flux:table.cell class="font-medium">{{ $row['product']->name }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $this->format($row['unpaid_quantity']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ count($row['unit_costs']) === 1 ? $money($row['unit_costs'][0]) : __('Varios') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $money($row['pending']) }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($covered === null)
                                            <span class="text-zinc-500">—</span>
                                        @elseif (bccomp($left, '0', 2) === 0)
                                            <flux:badge size="sm" color="green">{{ __('Se liquida') }}</flux:badge>
                                        @else
                                            <span class="text-sm">{{ __('Abona :covered · quedan :left', ['covered' => $money($covered), 'left' => $money($left)]) }}</span>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5"><flux:text class="py-6 text-center">{{ __('No se le debe nada.') }}</flux:text></flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                    <div class="flex justify-end gap-6 px-4 pb-4 font-semibold">
                        <span>{{ __('Total por pagar') }}</span>
                        <span class="tabular-nums">{{ $money($this->pending) }}</span>
                    </div>
                </flux:card>

                <flux:card class="flex flex-col gap-3 p-0">
                    <flux:heading class="px-4 pt-4">{{ __('Pagos anteriores') }}</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Fecha') }}</flux:table.column>
                            <flux:table.column>{{ __('Forma') }}</flux:table.column>
                            <flux:table.column>{{ __('Referencia') }}</flux:table.column>
                            <flux:table.column>{{ __('Cubrió') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Monto') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->payments as $payment)
                                <flux:table.row :key="$payment->id">
                                    <flux:table.cell class="whitespace-nowrap">{{ $payment->paid_at->translatedFormat('j M Y') }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->method->label() }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->reference ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $payment->allocations->map(fn ($allocation) => $allocation->lot->product->name)->unique()->join(', ') ?: '—' }}
                                        @if ($payment->note)
                                            <span class="block text-sm text-zinc-500">{{ $payment->note }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $money($payment->amount) }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5"><flux:text class="py-4 text-center">{{ __('Sin pagos registrados.') }}</flux:text></flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            </div>

            <div>
                @can(\App\Models\Permission::PaySuppliers->value)
                    @if ($this->products->isNotEmpty())
                        <flux:card>
                            <form wire:submit="registerPayment" class="flex flex-col gap-4">
                                <flux:heading>{{ __('Registrar pago') }}</flux:heading>
                                <flux:input wire:model.live.debounce.400ms="amount" :label="__('Monto')" type="number" step="0.01" min="0" icon="currency-dollar" :disabled="$spread === 'manual'" />
                                <flux:select wire:model="method" :label="__('Forma')">
                                    @foreach (\App\Models\PaymentMethod::cases() as $paymentMethod)
                                        <flux:select.option :value="$paymentMethod->value">{{ $paymentMethod->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:input wire:model="reference" :label="__('Referencia')" />

                                <flux:radio.group wire:model.live="spread" :label="__('Repartir el pago')">
                                    <flux:radio value="auto" :label="__('Liquidar lo más antiguo primero')" />
                                    <flux:radio value="manual" :label="__('O elige a mano qué productos cubre')" />
                                </flux:radio.group>

                                @if ($spread === 'manual')
                                    <div class="flex flex-col gap-2">
                                        @foreach ($this->products as $row)
                                            <div class="flex items-center justify-between gap-3" wire:key="spread-{{ $row['product']->id }}">
                                                <span class="text-sm">{{ $row['product']->name }}</span>
                                                <div class="w-32"><flux:input wire:model.live.debounce.400ms="product_amounts.{{ $row['product']->id }}" type="number" step="0.01" min="0" size="sm" :placeholder="number_format((float) $row['pending'], 2, '.', '')" /></div>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($preview['by_product'] !== [])
                                    <ul class="flex flex-col gap-1 text-sm">
                                        @foreach ($this->products as $row)
                                            @if (isset($preview['by_product'][$row['product']->id]))
                                                <li class="flex justify-between gap-3" wire:key="preview-{{ $row['product']->id }}">
                                                    <span>{{ $row['product']->name }}{{ bccomp($preview['by_product'][$row['product']->id], $row['pending'], 2) === -1 ? ' '.__('(parcial)') : '' }}</span>
                                                    <span class="tabular-nums">{{ $money($preview['by_product'][$row['product']->id]) }}</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($preview['error'])
                                    <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ $preview['error'] }}</flux:text>
                                @endif

                                <div class="flex items-center justify-between">
                                    <flux:text>{{ __('Queda por pagar') }}</flux:text>
                                    <span class="text-lg font-semibold tabular-nums">{{ $money($preview['remaining']) }}</span>
                                </div>

                                <flux:input wire:model="note" :label="__('Nota')" />

                                <flux:error name="amount" />
                                <flux:error name="product_amounts" />
                                <flux:error name="cash_session" />
                                <flux:error name="authorization" />

                                <flux:button type="submit" variant="primary" data-test="register-supplier-payment-button">{{ __('Guardar pago') }}</flux:button>
                                @if ($method === 'cash')
                                    <flux:text size="sm">{{ __('Sale de la caja abierta.') }}</flux:text>
                                @endif
                            </form>
                        </flux:card>
                    @endif
                @endcan
            </div>
        </div>
    @endif

    <flux:modal name="edit-supplier" class="md:w-[30rem]">
        <form wire:submit="saveSupplier" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Editar proveedor') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Nombre')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="phone" :label="__('Teléfono')" />
                <flux:input wire:model="email" type="email" :label="__('Correo')" />
            </div>
            <flux:radio.group wire:model="settlement_type" :label="__('Cómo trabaja')" variant="segmented">
                @foreach (\App\Models\SupplierSettlementType::cases() as $type)
                    <flux:radio :value="$type->value" :label="$type->label()" />
                @endforeach
            </flux:radio.group>
            <flux:error name="settlement_type" />
            <flux:input wire:model="rfc" :label="__('RFC')" />
            <flux:textarea wire:model="notes" :label="__('Notas')" rows="2" />
            <flux:switch wire:model="is_active" :label="__('Activo')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-supplier-button">{{ __('Guardar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

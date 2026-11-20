<?php

use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\SaleStatus;
use App\Services\CustomerBalanceService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ficha del cliente')] class extends Component {
    public ?Customer $customer = null;

    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    /** 'cash' or 'credit'. */
    public string $kind = 'cash';
    public bool $unlimited_credit = true;
    public string $credit_limit = '';
    public string $discount_percent = '';

    public string $rfc = '';
    public string $tax_system = '';
    public string $cfdi_use = '';
    public string $zip_code = '';

    public string $new_destination_name = '';

    /** @var array<int, string> destination names being edited, by id */
    public array $destination_names = [];

    public function mount(?Customer $customer = null): void
    {
        if ($customer === null || ! $customer->exists) {
            $this->customer = null;

            return;
        }

        $this->customer = $customer;
        $this->fill([
            'name' => $customer->name,
            'phone' => (string) $customer->phone,
            'email' => (string) $customer->email,
            'address' => (string) $customer->address,
            'kind' => $customer->hasCredit() ? 'credit' : 'cash',
            'unlimited_credit' => $customer->credit_limit === null,
            'credit_limit' => (string) $customer->credit_limit,
            'discount_percent' => bccomp($customer->discount_rate, '0', 4) === 1 ? rtrim(rtrim(bcmul($customer->discount_rate, '100', 2), '0'), '.') : '',
            'rfc' => (string) $customer->rfc,
            'tax_system' => (string) $customer->tax_system,
            'cfdi_use' => (string) $customer->cfdi_use,
            'zip_code' => (string) $customer->zip_code,
        ]);
        $this->destination_names = $customer->destinations()->pluck('name', 'id')->all();
    }

    /**
     * Balance of each account (destination), plus the sales without one.
     *
     * @return Collection<int, array{name: string, balance: string}>
     */
    #[Computed]
    public function accountBalances(): Collection
    {
        if ($this->customer === null) {
            return collect();
        }

        $balances = app(CustomerBalanceService::class);

        return $this->customer->destinations()->orderBy('id')->get()
            ->mapWithKeys(fn (CustomerDestination $destination) => [$destination->id => [
                'name' => $destination->name,
                'balance' => $balances->destinationBalance($destination),
            ]]);
    }

    /**
     * @return array{balance: string, since: string, sales: int, sold: string}
     */
    #[Computed]
    public function summary(): array
    {
        $completed = $this->customer->sales()->where('status', SaleStatus::Completed);

        return [
            'balance' => app(CustomerBalanceService::class)->balance($this->customer),
            'since' => $this->customer->created_at->translatedFormat('j M Y'),
            'sales' => (clone $completed)->count(),
            'sold' => (string) (clone $completed)->sum('total'),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['cash', 'credit'])],
            'unlimited_credit' => ['boolean'],
            'credit_limit' => [Rule::requiredIf($this->kind === 'credit' && ! $this->unlimited_credit), 'nullable', 'numeric', 'gt:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'tax_system' => ['nullable', 'string', 'max:3'],
            'cfdi_use' => ['nullable', 'string', 'max:4'],
            'zip_code' => ['nullable', 'digits:5'],
        ], attributes: [
            'credit_limit' => __('límite de crédito'),
            'discount_percent' => __('descuento fijo'),
            'zip_code' => __('código postal'),
        ]);

        $attributes = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?: null,
            'email' => $validated['email'] ?: null,
            'address' => $validated['address'] ?: null,
            'credit_enabled' => $validated['kind'] === 'credit',
            'credit_limit' => $validated['kind'] === 'credit' && ! $validated['unlimited_credit'] ? $validated['credit_limit'] : null,
            'discount_rate' => filled($validated['discount_percent']) ? bcdiv((string) $validated['discount_percent'], '100', 4) : 0,
            'rfc' => filled($validated['rfc']) ? mb_strtoupper($validated['rfc']) : null,
            'tax_system' => $validated['tax_system'] ?: null,
            'cfdi_use' => $validated['cfdi_use'] ?: null,
            'zip_code' => $validated['zip_code'] ?: null,
        ];

        DB::transaction(function () use ($attributes): void {
            if ($this->customer === null) {
                $this->customer = Customer::create($attributes);
            } else {
                $this->customer->update($attributes);
            }

            foreach ($this->destination_names as $id => $name) {
                if (filled($name)) {
                    $this->customer->destinations()->whereKey($id)->update(['name' => $name]);
                }
            }
        });

        Flux::toast(variant: 'success', text: __('Cliente guardado.'));

        $this->redirectRoute('customers.show', $this->customer, navigate: true);
    }

    public function addDestination(): void
    {
        $validated = $this->validate(['new_destination_name' => ['required', 'string', 'max:255']], attributes: ['new_destination_name' => __('nombre de la cuenta')]);

        $destination = $this->customer->destinations()->create(['name' => $validated['new_destination_name']]);

        $this->destination_names[$destination->id] = $destination->name;
        $this->reset('new_destination_name');
        unset($this->accountBalances);
    }

    /**
     * Delete a customer without sales; one with sales is deactivated instead.
     */
    public function delete(): void
    {
        $hasHistory = $this->customer->sales()->exists() || $this->customer->invoices()->exists() || $this->customer->payments()->exists();

        if ($hasHistory) {
            $this->customer->update(['is_active' => false]);
        } else {
            DB::transaction(function (): void {
                $this->customer->destinations()->delete();
                $this->customer->delete();
            });
        }

        Flux::toast(variant: 'success', text: $hasHistory ? __('El cliente tiene movimientos: se desactivó en lugar de eliminarse.') : __('Cliente eliminado.'));

        $this->redirectRoute('customers.index', navigate: true);
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('customers.index')" wire:navigate>{{ __('Clientes') }}</flux:button>
            <flux:heading size="xl" class="mt-2">{{ $customer?->name ?? __('Nuevo cliente') }}</flux:heading>
            @if ($customer && Route::has('customers.statement'))
                <flux:link :href="route('customers.statement', $customer)" wire:navigate>{{ __('Ver estado de cuenta') }}</flux:link>
            @endif
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('customers.index')" wire:navigate variant="ghost">{{ __('Cancelar') }}</flux:button>
            <flux:button variant="primary" wire:click="save" data-test="save-customer-button">{{ __('Guardar cambios') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('Datos generales') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Nombre')" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="phone" :label="__('Teléfono')" />
                    <flux:input wire:model="email" :label="__('Correo')" type="email" />
                </div>
                <flux:input wire:model="address" :label="__('Dirección')" />
                <flux:radio.group wire:model.live="kind" :label="__('Tipo de cliente')" variant="segmented" class="w-fit">
                    <flux:radio value="cash" :label="__('Contado')" />
                    <flux:radio value="credit" :label="__('Crédito')" />
                </flux:radio.group>
            </flux:card>

            @if ($customer)
                <flux:card class="flex flex-col gap-4">
                    <div>
                        <flux:heading>{{ __('Cuentas o destinos') }}</flux:heading>
                        <flux:text size="sm">{{ __('Cada venta y cada abono se liga a una cuenta, y cada cuenta lleva su propio saldo.') }}</flux:text>
                    </div>
                    @foreach ($this->accountBalances as $id => $account)
                        <div wire:key="destination-{{ $id }}" class="flex items-end gap-3">
                            <div class="flex-1"><flux:input wire:model="destination_names.{{ $id }}" :label="__('Nombre')" /></div>
                            <div class="w-36 text-end">
                                <flux:text size="sm">{{ __('Saldo') }}</flux:text>
                                <flux:heading class="tabular-nums">{{ $money($account['balance']) }}</flux:heading>
                            </div>
                        </div>
                    @endforeach
                    <form wire:submit="addDestination" class="flex items-end gap-3">
                        <div class="flex-1"><flux:input wire:model="new_destination_name" :label="__('Agregar cuenta')" :placeholder="__('Rancho, ciudad…')" /></div>
                        <flux:button type="submit" icon="plus">{{ __('Agregar') }}</flux:button>
                    </form>
                    <flux:error name="new_destination_name" />
                </flux:card>
            @endif

            <flux:card class="flex flex-col gap-4">
                <div>
                    <flux:heading>{{ __('Datos fiscales') }}</flux:heading>
                    <flux:text size="sm">{{ __('Solo si pide factura. Se llenan solos en cada factura.') }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="rfc" :label="__('RFC')" maxlength="13" />
                    <flux:input wire:model="zip_code" :label="__('Código postal fiscal')" maxlength="5" />
                    <flux:input wire:model="tax_system" :label="__('Régimen fiscal')" :placeholder="__('Ej. 601')" maxlength="3" />
                    <flux:input wire:model="cfdi_use" :label="__('Uso del CFDI')" :placeholder="__('Ej. G03')" maxlength="4" />
                </div>
            </flux:card>
        </div>

        <div class="flex flex-col gap-6">
            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('Crédito y descuento') }}</flux:heading>
                @if ($kind === 'credit')
                    <flux:checkbox wire:model.live="unlimited_credit" :label="__('Crédito abierto, sin límite')" />
                    @unless ($unlimited_credit)
                        <flux:input wire:model="credit_limit" :label="__('Límite de crédito')" type="number" step="0.01" min="0" icon="currency-dollar" :description="__('Si se rebasa, la venta pide autorización del dueño.')" />
                    @endunless
                @else
                    <flux:text size="sm">{{ __('Un cliente de contado paga completa cada venta.') }}</flux:text>
                @endif
                <flux:input wire:model="discount_percent" :label="__('Descuento fijo')" type="number" step="0.5" min="0" max="99" icon-trailing="percent-badge" :description="__('Se aplica solo en cada venta a este cliente.')" />
            </flux:card>

            @if ($customer)
                <flux:card class="flex flex-col gap-2">
                    <flux:heading>{{ __('Resumen') }}</flux:heading>
                    <div class="flex justify-between text-sm"><span>{{ __('Saldo total') }}</span><span class="font-semibold tabular-nums">{{ $money($this->summary['balance']) }}</span></div>
                    <div class="flex justify-between text-sm"><span>{{ __('Cliente desde') }}</span><span>{{ $this->summary['since'] }}</span></div>
                    <div class="flex justify-between text-sm"><span>{{ __('Compras') }}</span><span>{{ trans_choice(':count venta|:count ventas', $this->summary['sales']) }} · {{ $money($this->summary['sold']) }}</span></div>
                </flux:card>

                <flux:button variant="danger" icon="trash" wire:click="delete" wire:confirm="{{ __('¿Eliminar este cliente? Si tiene movimientos solo se desactiva.') }}">{{ __('Eliminar cliente') }}</flux:button>
            @endif

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    @foreach ($errors->all() as $message)
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    @endforeach
                </flux:callout>
            @endif
        </div>
    </div>
</div>

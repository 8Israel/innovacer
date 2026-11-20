<?php

use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use App\Models\Warehouse;
use App\Services\SupplierDebtService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Proveedores')] class extends Component {
    public string $name = '';

    public string $settlement_type = 'consignment';

    public string $phone = '';

    public string $email = '';

    public string $rfc = '';

    public string $notes = '';

    /** Create a warehouse for the supplier's product (like Bodega Ortitec or Consigna Francisco). */
    public bool $with_warehouse = false;

    /**
     * @return Collection<int, array{supplier: Supplier, pending: string, products: int, last_paid_at: Carbon|null}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $debts = app(SupplierDebtService::class);

        return Supplier::query()
            ->where('is_active', true)
            ->with(['lentWarehouses'])
            ->withMax('payments', 'paid_at')
            ->withCount(['lots as products_count' => fn ($lots) => $lots->select(DB::raw('count(distinct product_id)'))])
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier): array => [
                'supplier' => $supplier,
                'pending' => $debts->pending($supplier),
                'products' => (int) $supplier->products_count,
                'last_paid_at' => $supplier->payments_max_paid_at ? Carbon::parse($supplier->payments_max_paid_at) : null,
            ])
            ->sortByDesc(fn (array $row) => (float) $row['pending'])
            ->values();
    }

    #[Computed]
    public function totalPending(): string
    {
        return $this->rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['pending'], 2), '0.00');
    }

    public function save(): void
    {
        $this->rfc = mb_strtoupper(trim($this->rfc));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')],
            'settlement_type' => ['required', Rule::enum(SupplierSettlementType::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['rfc.regex' => __('El RFC no tiene el formato correcto.')]);

        $supplier = DB::transaction(function () use ($validated): Supplier {
            $supplier = Supplier::create([...array_map(fn ($value) => filled($value) ? $value : null, $validated), 'is_active' => true]);

            if ($this->with_warehouse) {
                $inKind = $supplier->settlement_type === SupplierSettlementType::InKind;

                Warehouse::create([
                    'name' => ($inKind ? __('Consigna') : __('Bodega')).' '.$supplier->name,
                    'code' => $this->warehouseCode($supplier->name),
                    'lender_id' => $inKind ? $supplier->id : null,
                    'is_sellable' => false,
                ]);
            }

            return $supplier;
        });

        $this->redirectRoute('suppliers.show', $supplier, navigate: true);
    }

    private function warehouseCode(string $name): string
    {
        $base = Str::upper(Str::substr(Str::ascii(Str::replace(' ', '', $name)), 0, 3));
        $code = $base;

        for ($suffix = 2; Warehouse::query()->where('code', $code)->exists(); $suffix++) {
            $code = $base.$suffix;
        }

        return $code;
    }
}; ?>

@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Proveedores') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Lo que se le debe a cada uno por lo vendido de su producto') }}</flux:text>
        </div>
        <flux:modal.trigger name="new-supplier">
            <flux:button variant="primary" icon="plus" data-test="new-supplier-button">{{ __('Nuevo proveedor') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:card class="p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Proveedor') }}</flux:table.column>
                <flux:table.column>{{ __('Cómo trabaja') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Productos') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Por pagar') }}</flux:table.column>
                <flux:table.column>{{ __('Último pago') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->rows as $row)
                    @php
                        $supplier = $row['supplier'];
                    @endphp
                    <flux:table.row :key="$supplier->id">
                        <flux:table.cell>
                            <flux:link :href="route('suppliers.show', $supplier)" wire:navigate class="font-medium">{{ $supplier->name }}</flux:link>
                            @if ($supplier->lentWarehouses->isNotEmpty())
                                <span class="block text-sm text-zinc-500">{{ __('Su producto está en :warehouses', ['warehouses' => $supplier->lentWarehouses->pluck('name')->join(', ')]) }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="match ($supplier->settlement_type) { \App\Models\SupplierSettlementType::Consignment => 'blue', \App\Models\SupplierSettlementType::InKind => 'amber', default => 'zinc' }">{{ $supplier->settlement_type->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['products'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="font-medium tabular-nums">
                            {{ $supplier->settlement_type === \App\Models\SupplierSettlementType::InKind ? '—' : $money($row['pending']) }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $row['last_paid_at']?->translatedFormat('j M') ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5"><flux:text class="py-6 text-center">{{ __('Todavía no hay proveedores.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <div class="flex flex-wrap justify-between gap-3">
        <flux:text>{{ trans_choice(':count proveedor|:count proveedores', $this->rows->count()) }}</flux:text>
        <flux:text class="font-medium">{{ __('Total por pagar :amount', ['amount' => $money($this->totalPending)]) }}</flux:text>
    </div>

    <flux:modal name="new-supplier" class="md:w-[30rem]">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Nuevo proveedor') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Nombre')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="phone" :label="__('Teléfono')" />
                <flux:input wire:model="email" type="email" :label="__('Correo')" />
            </div>
            <flux:radio.group wire:model.live="settlement_type" :label="__('Cómo trabaja')" variant="segmented">
                @foreach (\App\Models\SupplierSettlementType::cases() as $type)
                    <flux:radio :value="$type->value" :label="$type->label()" />
                @endforeach
            </flux:radio.group>
            <flux:text size="sm">{{ ucfirst(\App\Models\SupplierSettlementType::from($settlement_type)->description()) }}</flux:text>
            <flux:checkbox wire:model="with_warehouse" :label="__('Tiene su propio almacén aquí')" :description="__('Se crea el almacén para su producto.')" />
            <flux:input wire:model="rfc" :label="__('RFC')" />
            <flux:textarea wire:model="notes" :label="__('Notas')" :placeholder="__('Contacto, condiciones…')" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-supplier-button">{{ __('Guardar proveedor') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

<?php

use App\Models\Customer;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Clientes')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $name = '';

    public string $rfc = '';

    public string $email = '';

    public string $tax_system = '';

    public string $cfdi_use = '';

    public string $zip_code = '';

    public ?int $deletingId = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', 'max:13', 'unique:customers,rfc,'.$this->editingId],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_system' => ['required', 'string', 'max:3'],
            'cfdi_use' => ['required', 'string', 'max:4'],
            'zip_code' => ['required', 'string', 'size:5'],
        ];
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('customer-form')->show();
    }

    public function edit(int $customerId): void
    {
        $customer = Customer::findOrFail($customerId);

        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->rfc = $customer->rfc;
        $this->email = (string) $customer->email;
        $this->tax_system = $customer->tax_system;
        $this->cfdi_use = $customer->cfdi_use;
        $this->zip_code = $customer->zip_code;

        Flux::modal('customer-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate();
        $validated['rfc'] = strtoupper($validated['rfc']);

        Customer::updateOrCreate(['id' => $this->editingId], $validated);

        Flux::modal('customer-form')->close();

        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Cliente guardado.'));
    }

    public function confirmDelete(int $customerId): void
    {
        $this->deletingId = $customerId;

        Flux::modal('customer-delete')->show();
    }

    public function delete(): void
    {
        Customer::whereKey($this->deletingId)->delete();

        $this->deletingId = null;

        Flux::modal('customer-delete')->close();

        Flux::toast(variant: 'success', text: __('Cliente eliminado.'));
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'rfc', 'email', 'tax_system', 'cfdi_use', 'zip_code']);
        $this->resetValidation();
    }

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    #[Computed]
    public function customers()
    {
        return Customer::query()->orderBy('name')->paginate(10);
    }
}; ?>

<div class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Clientes') }}</flux:heading>
            <flux:subheading>{{ __('Datos fiscales de tus clientes para facturación.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">
            {{ __('Nuevo cliente') }}
        </flux:button>
    </div>

    <flux:table :paginate="$this->customers">
        <flux:table.columns>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('RFC') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('C.P. fiscal') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->customers as $customer)
                <flux:table.row :key="$customer->id">
                    <flux:table.cell>{{ $customer->name }}</flux:table.cell>
                    <flux:table.cell>{{ $customer->rfc }}</flux:table.cell>
                    <flux:table.cell>{{ $customer->email ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $customer->zip_code }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" icon="pencil" wire:click="edit({{ $customer->id }})">
                                {{ __('Editar') }}
                            </flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $customer->id }})">
                                {{ __('Eliminar') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                        {{ __('Aún no hay clientes registrados.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="customer-form" class="md:w-96" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Editar cliente') : __('Nuevo cliente') }}
            </flux:heading>

            <flux:input wire:model="name" :label="__('Nombre / Razón social')" required autofocus />

            <flux:input wire:model="rfc" :label="__('RFC')" required maxlength="13" />

            <flux:input wire:model="email" :label="__('Email')" type="email" />

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="tax_system" :label="__('Régimen fiscal')" required description="c_RegimenFiscal, ej. 601" />
                <flux:input wire:model="cfdi_use" :label="__('Uso de CFDI')" required description="c_UsoCFDI, ej. G03" />
            </div>

            <flux:input wire:model="zip_code" :label="__('Código postal fiscal')" required maxlength="5" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Guardar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="customer-delete" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('¿Eliminar cliente?') }}</flux:heading>
            <flux:text>{{ __('Esta acción no se puede deshacer.') }}</flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Eliminar') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

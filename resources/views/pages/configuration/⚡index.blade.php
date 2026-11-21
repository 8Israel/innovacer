<?php

use App\Models\Permission;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\SettingsService;
use App\Services\UserAccountService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configuración')] class extends Component {
    public ?int $editing_user_id = null;

    public string $user_name = '';

    public string $user_email = '';

    public string $user_role = 'clerk';

    public bool $user_active = true;

    /** The temporary password shown once, when creating a user or resetting theirs. */
    public string $temporary_password = '';

    public ?int $reset_user_id = null;

    public ?int $editing_warehouse_id = null;

    public string $warehouse_name = '';

    public string $warehouse_code = '';

    /** '' when the business owns what is stored, or the id of the lending supplier. */
    public string $warehouse_lender_id = '';

    public bool $warehouse_sellable = false;

    public bool $warehouse_active = true;

    public string $business_name = '';

    public string $business_address = '';

    public string $business_phone = '';

    public string $business_rfc = '';

    public string $ticket_printer_width = '80';

    public function mount(SettingsService $settings): void
    {
        $this->business_name = $settings->get('business_name', config('app.name')) ?? '';
        $this->business_address = $settings->get('business_address') ?? '';
        $this->business_phone = $settings->get('business_phone') ?? '';
        $this->business_rfc = $settings->get('business_rfc') ?? '';
        $this->ticket_printer_width = $settings->get('ticket_printer_width', '80') ?? '80';
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->orderByDesc('is_active')->orderBy('role')->orderBy('name')->get();
    }

    /**
     * Last activity of each user on any device.
     *
     * @return Collection<int, Carbon>
     */
    #[Computed]
    public function lastAccess(): Collection
    {
        return DB::table(config('session.table'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(last_activity) as last_activity')
            ->pluck('last_activity', 'user_id')
            ->map(fn ($timestamp) => Carbon::createFromTimestamp($timestamp));
    }

    /**
     * @return Collection<int, Warehouse>
     */
    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('lender')->orderByDesc('is_active')->orderByDesc('is_sellable')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Supplier>
     */
    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function createUser(): void
    {
        $this->reset('editing_user_id', 'user_name', 'user_email', 'user_role', 'user_active');
        $this->resetErrorBag();
        $this->temporary_password = app(UserAccountService::class)->temporaryPassword();

        Flux::modal('user-form')->show();
    }

    public function editUser(User $user): void
    {
        $this->editing_user_id = $user->id;
        $this->user_name = $user->name;
        $this->user_email = $user->email;
        $this->user_role = $user->role->value;
        $this->user_active = $user->is_active;
        $this->resetErrorBag();

        Flux::modal('user-form')->show();
    }

    public function saveUser(UserAccountService $accounts): void
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $this->validate([
            'user_name' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editing_user_id)],
            'user_role' => ['required', Rule::enum(UserRole::class)],
            'temporary_password' => [Rule::requiredIf($this->editing_user_id === null), 'nullable', 'string', 'min:8'],
        ]);

        if ($this->editing_user_id === null) {
            $user = $accounts->create($validated['user_name'], $validated['user_email'], UserRole::from($validated['user_role']), $this->temporary_password);
            Flux::toast(variant: 'success', text: __('Usuario de :name creado. Dale su contraseña temporal en persona.', ['name' => $user->name]));
        } else {
            $accounts->update(
                auth()->user(),
                User::query()->findOrFail($this->editing_user_id),
                $validated['user_name'],
                $validated['user_email'],
                UserRole::from($validated['user_role']),
                $this->user_active,
            );
            Flux::toast(variant: 'success', text: __('Usuario actualizado.'));
        }

        unset($this->users);
        Flux::modal('user-form')->close();
    }

    public function startPasswordReset(User $user): void
    {
        $this->reset_user_id = $user->id;
        $this->temporary_password = app(UserAccountService::class)->temporaryPassword();

        Flux::modal('reset-password')->show();
    }

    public function generateAnotherPassword(UserAccountService $accounts): void
    {
        $this->temporary_password = $accounts->temporaryPassword();
    }

    public function resetPassword(UserAccountService $accounts): void
    {
        Gate::authorize(Permission::ManageSettings->value);

        $user = User::query()->findOrFail($this->reset_user_id);

        if ($user->is(auth()->user())) {
            throw ValidationException::withMessages(['temporary_password' => __('Tu propia contraseña se cambia en tu perfil.')]);
        }

        $accounts->resetPassword($user, $this->temporary_password);
        $this->reset('reset_user_id', 'temporary_password');
        Flux::modal('reset-password')->close();

        Flux::toast(variant: 'success', text: __('Contraseña de :name restablecida.', ['name' => $user->name]));
    }

    public function createWarehouse(): void
    {
        $this->reset('editing_warehouse_id', 'warehouse_name', 'warehouse_code', 'warehouse_lender_id', 'warehouse_sellable', 'warehouse_active');
        $this->resetErrorBag();

        Flux::modal('warehouse-form')->show();
    }

    public function editWarehouse(Warehouse $warehouse): void
    {
        $this->editing_warehouse_id = $warehouse->id;
        $this->warehouse_name = $warehouse->name;
        $this->warehouse_code = $warehouse->code;
        $this->warehouse_lender_id = (string) $warehouse->lender_id;
        $this->warehouse_sellable = $warehouse->is_sellable;
        $this->warehouse_active = $warehouse->is_active;
        $this->resetErrorBag();

        Flux::modal('warehouse-form')->show();
    }

    /**
     * Who owns what is stored depends on the warehouse lender, so the lender cannot
     * change, nor the warehouse be deactivated, while it still has stock.
     */
    public function saveWarehouse(): void
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $this->validate([
            'warehouse_name' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->ignore($this->editing_warehouse_id)],
            'warehouse_code' => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')->ignore($this->editing_warehouse_id)],
            'warehouse_lender_id' => ['nullable', Rule::exists('suppliers', 'id')],
        ]);

        $attributes = [
            'name' => $validated['warehouse_name'],
            'code' => mb_strtoupper($validated['warehouse_code']),
            'lender_id' => filled($validated['warehouse_lender_id']) ? (int) $validated['warehouse_lender_id'] : null,
            'is_sellable' => $this->warehouse_sellable,
            'is_active' => $this->warehouse_active,
        ];

        DB::transaction(function () use ($attributes): void {
            if ($this->editing_warehouse_id === null) {
                Warehouse::create($attributes);

                return;
            }

            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($this->editing_warehouse_id);
            $hasStock = Stock::query()->where('warehouse_id', $warehouse->id)->where('quantity', '>', 0)->exists();

            if ($hasStock && $attributes['lender_id'] !== $warehouse->lender_id) {
                throw ValidationException::withMessages(['warehouse_lender_id' => __('Tiene producto guardado: sácalo con un traspaso antes de cambiar de dueño.')]);
            }

            if ($hasStock && ! $attributes['is_active']) {
                throw ValidationException::withMessages(['warehouse_active' => __('Tiene producto guardado: sácalo con un traspaso antes de desactivarlo.')]);
            }

            $warehouse->update($attributes);
        });

        unset($this->warehouses);
        Flux::modal('warehouse-form')->close();
        Flux::toast(variant: 'success', text: __('Almacén guardado.'));
    }

    public function saveBusiness(SettingsService $settings): void
    {
        Gate::authorize(Permission::ManageSettings->value);

        $this->business_rfc = mb_strtoupper(trim($this->business_rfc));

        $validated = $this->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:50'],
            'business_rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u'],
            'ticket_printer_width' => ['required', 'in:80,58'],
        ], ['business_rfc.regex' => __('El RFC no tiene el formato correcto.')]);

        $settings->save($validated);

        Flux::toast(variant: 'success', text: __('Datos del negocio guardados.'));
    }

    public function warehouseOwner(Warehouse $warehouse): string
    {
        if ($warehouse->lender === null) {
            return __('Dueño: :business o el proveedor de cada lote', ['business' => setting('business_name', config('app.name'))]);
        }

        return __('Dueño: :lender · :terms', [
            'lender' => $warehouse->lender->name,
            'terms' => match ($warehouse->lender->settlement_type) {
                SupplierSettlementType::InKind => __('préstamos que se reponen'),
                SupplierSettlementType::Consignment => __('consigna, se paga lo vendido'),
                SupplierSettlementType::Purchase => __('compra'),
            },
        ]);
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Configuración') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Solo el dueño puede entrar aquí') }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-6">
            {{-- Usuarios --}}
            <flux:card class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ __('Usuarios') }}</flux:heading>
                    <flux:button size="sm" icon="plus" wire:click="createUser" data-test="new-user-button">{{ __('Nuevo usuario') }}</flux:button>
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Nombre') }}</flux:table.column>
                        <flux:table.column>{{ __('Rol') }}</flux:table.column>
                        <flux:table.column>{{ __('Último acceso') }}</flux:table.column>
                        <flux:table.column />
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->users as $user)
                            @php
                                $lastAccess = $this->lastAccess[$user->id] ?? null;
                            @endphp
                            <flux:table.row :key="$user->id" class="{{ $user->is_active ? '' : 'opacity-60' }}">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $user->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ $user->email }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $user->role->label() }}
                                    @unless ($user->is_active)
                                        <flux:badge size="sm" color="zinc">{{ __('Inactivo') }}</flux:badge>
                                    @endunless
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    {{ $lastAccess ? ($lastAccess->isToday() ? __('Hoy :time', ['time' => $lastAccess->format('H:i')]) : $lastAccess->translatedFormat('j M · H:i')) : '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex flex-col items-end gap-1">
                                        <flux:button size="xs" variant="ghost" wire:click="editUser({{ $user->id }})">{{ __('Editar') }}</flux:button>
                                        @unless ($user->is(auth()->user()))
                                            <flux:button size="xs" variant="ghost" wire:click="startPasswordReset({{ $user->id }})">{{ __('Nueva contraseña') }}</flux:button>
                                        @endunless
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            {{-- Qué puede hacer cada rol --}}
            <flux:card class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Qué puede hacer cada rol') }}</flux:heading>
                    <flux:text size="sm">{{ __('Fijos por rol. Si algún día se necesita cambiarlos, se ajustan en el sistema, no desde esta pantalla.') }}</flux:text>
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Acción') }}</flux:table.column>
                        @foreach (\App\Models\UserRole::cases() as $role)
                            <flux:table.column align="center">{{ $role->label() }}</flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach (\App\Models\Permission::cases() as $permission)
                            <flux:table.row :key="$permission->value">
                                <flux:table.cell>{{ $permission->label() }}</flux:table.cell>
                                @foreach (\App\Models\UserRole::cases() as $role)
                                    <flux:table.cell align="center">
                                        @if ($role->allows($permission))
                                            <span class="font-medium text-brand">{{ __('Sí') }}</span>
                                        @else
                                            <span class="text-zinc-500">{{ __('No') }}</span>
                                        @endif
                                    </flux:table.cell>
                                @endforeach
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>

        <div class="flex flex-col gap-6">
            {{-- Almacenes --}}
            <flux:card class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ __('Almacenes') }}</flux:heading>
                    <flux:button size="sm" icon="plus" wire:click="createWarehouse" data-test="new-warehouse-button">{{ __('Agregar') }}</flux:button>
                </div>
                <ul class="flex flex-col divide-y divide-line dark:divide-zinc-700">
                    @foreach ($this->warehouses as $warehouse)
                        <li class="flex items-center justify-between gap-3 py-3 {{ $warehouse->is_active ? '' : 'opacity-60' }}" wire:key="warehouse-{{ $warehouse->id }}">
                            <div>
                                <span class="font-medium">{{ $warehouse->name }}</span>
                                <span class="text-sm text-zinc-500">· {{ $warehouse->code }}</span>
                                @unless ($warehouse->is_active)
                                    <flux:badge size="sm" color="zinc">{{ __('Inactivo') }}</flux:badge>
                                @endunless
                                <span class="block text-sm text-zinc-500">
                                    {{ $this->warehouseOwner($warehouse) }}{{ $warehouse->is_sellable ? ' · '.__('se vende desde aquí') : '' }}
                                </span>
                            </div>
                            <flux:button size="xs" variant="ghost" wire:click="editWarehouse({{ $warehouse->id }})">{{ __('Editar') }}</flux:button>
                        </li>
                    @endforeach
                </ul>
            </flux:card>

            {{-- Datos del negocio --}}
            <flux:card>
                <form wire:submit="saveBusiness" class="flex flex-col gap-4">
                    <div>
                        <flux:heading size="lg">{{ __('Datos del negocio') }}</flux:heading>
                        <flux:text size="sm">{{ __('Salen en el ticket, la nota de venta y la factura') }}</flux:text>
                    </div>
                    <flux:input wire:model="business_name" :label="__('Nombre')" />
                    <flux:input wire:model="business_address" :label="__('Dirección')" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="business_phone" :label="__('Teléfono')" />
                        <flux:input wire:model="business_rfc" :label="__('RFC del negocio')" />
                    </div>
                    <flux:radio.group wire:model="ticket_printer_width" :label="__('Impresora de tickets')" variant="segmented">
                        <flux:radio value="80" :label="__('Térmica 80 mm')" />
                        <flux:radio value="58" :label="__('Térmica 58 mm')" />
                    </flux:radio.group>
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" data-test="save-business-button">{{ __('Guardar') }}</flux:button>
                    </div>
                </form>
            </flux:card>
        </div>
    </div>

    {{-- docs/diseno/UsuariosModales.html --}}
    <flux:modal name="user-form" class="md:w-[28rem]">
        <form wire:submit="saveUser" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editing_user_id ? __('Editar usuario') : __('Nuevo usuario') }}</flux:heading>
            <flux:input wire:model="user_name" :label="__('Nombre')" />
            <flux:input wire:model="user_email" type="email" :label="__('Correo para entrar')" />
            <flux:radio.group wire:model="user_role" :label="__('Rol')" variant="segmented">
                <flux:radio value="clerk" :label="__('Encargada')" />
                <flux:radio value="owner" :label="__('Dueño')" />
            </flux:radio.group>
            @if ($editing_user_id)
                <flux:switch wire:model="user_active" :label="__('Activo')" :description="__('Un usuario inactivo ya no puede entrar y se cierran sus sesiones.')" />
            @else
                <flux:field>
                    <flux:label>{{ __('Contraseña temporal') }}</flux:label>
                    <flux:input wire:model="temporary_password" class="font-mono" />
                    <flux:description>{{ __('Al entrar por primera vez le pedirá cambiarla') }}</flux:description>
                    <flux:error name="temporary_password" />
                </flux:field>
            @endif
            <flux:error name="user" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-user-button">{{ $editing_user_id ? __('Guardar') : __('Crear usuario') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reset-password" class="md:w-[28rem]">
        @php
            $resetUser = $reset_user_id ? $this->users->firstWhere('id', $reset_user_id) : null;
        @endphp
        <div class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Restablecer contraseña') }}</flux:heading>
                @if ($resetUser)
                    <flux:text>{{ __('Para :name (:role)', ['name' => $resetUser->name, 'role' => mb_strtolower($resetUser->role->label())]) }}</flux:text>
                @endif
            </div>
            <div>
                <flux:text size="sm">{{ __('Nueva contraseña temporal') }}</flux:text>
                <div class="mt-1 font-mono text-2xl font-semibold tracking-wide">{{ $temporary_password }}</div>
            </div>
            <flux:text size="sm">{{ __('Dásela en persona. Al entrar le pedirá poner una propia. Se cierra su sesión en otras computadoras.') }}</flux:text>
            <flux:error name="temporary_password" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="generateAnotherPassword">{{ __('Generar otra') }}</flux:button>
                <flux:button variant="primary" wire:click="resetPassword" data-test="reset-password-button">{{ __('Listo') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="warehouse-form" class="md:w-[28rem]">
        <form wire:submit="saveWarehouse" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editing_warehouse_id ? __('Editar almacén') : __('Nuevo almacén') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><flux:input wire:model="warehouse_name" :label="__('Nombre')" /></div>
                <flux:input wire:model="warehouse_code" :label="__('Clave')" :placeholder="__('Ej. MOS')" />
            </div>
            <flux:select wire:model="warehouse_lender_id" :label="__('Dueño del producto')">
                <flux:select.option value="">{{ __('Innovacer (propio)') }}</flux:select.option>
                @foreach ($this->suppliers as $supplier)
                    <flux:select.option :value="$supplier->id">{{ $supplier->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text size="sm">{{ __('Si el almacén es de un proveedor, todo lo que se guarda ahí es suyo.') }}</flux:text>
            <flux:switch wire:model="warehouse_sellable" :label="__('Se puede vender directo desde aquí')" :description="__('Si no se vende directo, el producto pasa al mostrador con un traspaso.')" />
            @if ($editing_warehouse_id)
                <flux:switch wire:model="warehouse_active" :label="__('Activo')" />
                <flux:error name="warehouse_active" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-warehouse-button">{{ $editing_warehouse_id ? __('Guardar') : __('Crear almacén') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-surface text-ink dark:bg-zinc-800 dark:text-white">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-line bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('home') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:cash-status />

            {{-- Sections from docs/diseno/MenuRoles.html; each appears once its screen exists. --}}
            <flux:sidebar.nav>
                @can(\App\Models\Permission::Sell->value)
                    <flux:sidebar.item icon="shopping-cart" :href="route('pos.index')" :current="request()->routeIs('pos.*')" wire:navigate>
                        {{ __('Punto de venta') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="receipt-percent" :href="route('sales.index')" :current="request()->routeIs('sales.*')" wire:navigate>
                        {{ __('Ventas') }}
                    </flux:sidebar.item>
                @endcan
                @can(\App\Models\Permission::ManageInventory->value)
                    <flux:sidebar.item icon="archive-box" :href="route('inventory.index')" :current="request()->routeIs('inventory.index', 'inventory.entry', 'inventory.kardex', 'inventory.count', 'inventory.import')" wire:navigate>
                        {{ __('Inventario') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="arrows-right-left" :href="route('inventory.transfer')" :current="request()->routeIs('inventory.transfer', 'inventory.loans')" wire:navigate>
                        {{ __('Traspasos') }}
                    </flux:sidebar.item>
                @endcan
                <flux:sidebar.item icon="cube" :href="route('products.index')" :current="request()->routeIs('products.*')" wire:navigate>
                    {{ __('Productos') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="users" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>
                    {{ __('Clientes y cobranza') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="truck" :href="route('suppliers.index')" :current="request()->routeIs('suppliers.*')" wire:navigate>
                    {{ __('Proveedores') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="banknotes" :href="route('cash.index')" :current="request()->routeIs('cash.*')" wire:navigate>
                    {{ __('Caja') }}
                </flux:sidebar.item>
                @can(\App\Models\Permission::Invoice->value)
                    <flux:sidebar.item icon="document-text" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>
                        {{ __('Facturación') }}
                    </flux:sidebar.item>
                @endcan
                @can(\App\Models\Permission::ViewReports->value)
                    <flux:sidebar.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>
                        {{ __('Reportes') }}
                    </flux:sidebar.item>
                @endcan
                @can(\App\Models\Permission::ManageSettings->value)
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('configuration.index')" :current="request()->routeIs('configuration.*')" wire:navigate>
                        {{ __('Configuración') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-line bg-white lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <x-user-menu-items />
            </flux:dropdown>
        </flux:header>

        @session('error')
            <div class="px-6 pt-6 lg:px-8">
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$value" />
            </div>
        @endsession

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

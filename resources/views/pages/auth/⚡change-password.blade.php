<?php

use App\Concerns\PasswordValidationRules;
use App\Services\UserSessionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Cambiar contraseña')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Replace the temporary password, close the user's other sessions and let them in.
     */
    public function changePassword(UserSessionService $sessions): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => [...$this->passwordRules(), 'different:current_password'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user = Auth::user();
        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $sessions->closeOthers($user, session()->getId());

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Cambia tu contraseña')"
        :description="__('Tu contraseña es temporal. Elige una nueva para seguir usando el sistema.')"
    />

    <form wire:submit="changePassword" class="flex flex-col gap-6">
        <flux:input
            wire:model="current_password"
            :label="__('Contraseña actual')"
            type="password"
            required
            autocomplete="current-password"
            viewable
        />

        <flux:input
            wire:model="password"
            :label="__('Nueva contraseña')"
            :description="__('Mínimo 8 caracteres')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Repite la nueva contraseña')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:button variant="primary" type="submit" class="w-full" data-test="change-password-button">
            {{ __('Cambiar contraseña') }}
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <flux:button variant="ghost" size="sm" type="submit">{{ __('Cerrar sesión') }}</flux:button>
    </form>
</div>

<?php

use App\Services\UserSessionService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sesiones activas')] class extends Component {
    /**
     * The user's open sessions, one per device or browser.
     *
     * @return Collection<int, array{id: string, ip_address: string|null, browser: string, platform: string, last_active_at: \Illuminate\Support\Carbon, is_current: bool}>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return app(UserSessionService::class)->sessionsFor(Auth::user(), session()->getId());
    }

    /**
     * Log one of the user's other devices out.
     */
    public function close(string $sessionId, UserSessionService $sessions): void
    {
        if ($sessionId === session()->getId()) {
            return;
        }

        $sessions->close(Auth::user(), $sessionId);
        unset($this->sessions);

        Flux::toast(variant: 'success', text: __('Sesión cerrada.'));
    }

    /**
     * Log every other device out, keeping this one.
     */
    public function closeOthers(UserSessionService $sessions): void
    {
        $sessions->closeOthers(Auth::user(), session()->getId());
        unset($this->sessions);

        Flux::toast(variant: 'success', text: __('Se cerraron las demás sesiones.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Sesiones activas') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Sesiones activas')" :subheading="__('Dispositivos con tu sesión abierta. Cierra los que no reconozcas o ya no uses.')">
        <div class="flex flex-col gap-3">
            @foreach ($this->sessions as $session)
                <div wire:key="session-{{ $session['id'] }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-col gap-1">
                        <flux:text class="font-medium text-zinc-800 dark:text-white">
                            {{ $session['browser'] }} · {{ $session['platform'] }}
                            @if ($session['is_current'])
                                <flux:badge size="sm" color="green" class="ms-2">{{ __('Este dispositivo') }}</flux:badge>
                            @endif
                        </flux:text>
                        <flux:text size="sm">
                            {{ $session['ip_address'] ?? __('IP desconocida') }} · {{ __('Último uso') }} {{ $session['last_active_at']->diffForHumans() }}
                        </flux:text>
                    </div>

                    @unless ($session['is_current'])
                        <flux:button size="sm" variant="ghost" wire:click="close('{{ $session['id'] }}')" data-test="close-session-button">
                            {{ __('Cerrar') }}
                        </flux:button>
                    @endunless
                </div>
            @endforeach

            @if ($this->sessions->where('is_current', false)->isNotEmpty())
                <div>
                    <flux:button variant="danger" wire:click="closeOthers" wire:confirm="{{ __('¿Cerrar la sesión en todos los demás dispositivos?') }}" data-test="close-other-sessions-button">
                        {{ __('Cerrar las demás sesiones') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>

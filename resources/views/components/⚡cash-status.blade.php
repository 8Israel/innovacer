<?php

use App\Models\CashSession;
use App\Services\CashSessionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /**
     * The open cash session, if the register is open.
     */
    #[Computed]
    public function openSession(): ?CashSession
    {
        return app(CashSessionService::class)->current();
    }

    /**
     * Refresh when a screen opens or closes the register.
     */
    #[On('cash-session-changed')]
    public function refreshStatus(): void
    {
        unset($this->openSession);
    }
}; ?>

<a href="{{ route('cash.index') }}" wire:navigate class="block px-2" data-test="cash-status">
    @if ($this->openSession)
        <flux:badge color="green" icon="lock-open" size="sm" class="w-full">
            {{ __('Caja abierta') }} · {{ $this->openSession->opened_at->format('H:i') }}
        </flux:badge>
    @else
        <flux:badge color="zinc" icon="lock-closed" size="sm" class="w-full">
            {{ __('Caja cerrada') }}
        </flux:badge>
    @endif
</a>

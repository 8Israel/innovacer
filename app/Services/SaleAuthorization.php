<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Someone approving a sale on the spot by typing their password, with the reason
 * that gets recorded on the sale.
 */
final readonly class SaleAuthorization
{
    public function __construct(
        public User $user,
        public string $password,
        public ?string $reason = null,
    ) {}

    /**
     * Check the password and the reason, and that the user may approve this.
     *
     * @throws ValidationException
     */
    public function verify(bool $ownerOnly, string $action): User
    {
        if (! Hash::check($this->password, $this->user->password)) {
            throw ValidationException::withMessages(['authorization' => __('La contraseña de :user no es correcta.', ['user' => $this->user->name])]);
        }

        if ($ownerOnly && ! $this->user->isOwner()) {
            throw ValidationException::withMessages(['authorization' => __('Solo el dueño puede autorizar :action.', ['action' => $action])]);
        }

        if (blank($this->reason)) {
            throw ValidationException::withMessages(['authorization' => __('Escribe el motivo de la autorización.')]);
        }

        return $this->user;
    }
}

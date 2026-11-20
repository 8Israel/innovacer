<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts managed by the owner from Configuración (docs/diseno/UsuariosModales.html).
 * New users and reset passwords get a temporary password that must be changed
 * on the next login, and there is always at least one active owner.
 */
class UserAccountService
{
    private const PASSWORD_WORDS = ['Laguna', 'Nogal', 'Mezquite', 'Sorgo', 'Cosecha', 'Surco', 'Huerta', 'Rancho', 'Semilla', 'Llano'];

    public function __construct(private UserSessionService $sessions) {}

    /**
     * Something like "Laguna-7315": easy to dictate, only meant to be used once.
     */
    public function temporaryPassword(): string
    {
        return self::PASSWORD_WORDS[array_rand(self::PASSWORD_WORDS)].'-'.random_int(1000, 9999);
    }

    public function create(string $name, string $email, UserRole $role, string $temporaryPassword): User
    {
        return User::query()->forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => $temporaryPassword,
            'role' => $role,
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }

    /**
     * Change the data, role or status of an account. A deactivated user is logged out everywhere.
     */
    public function update(User $actor, User $user, string $name, string $email, UserRole $role, bool $isActive): User
    {
        return DB::transaction(function () use ($actor, $user, $name, $email, $role, $isActive): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->is($actor) && (! $isActive || $role !== $user->role)) {
                throw ValidationException::withMessages(['user' => __('No puedes desactivarte ni cambiar tu propio rol.')]);
            }

            $losesOwner = $user->role === UserRole::Owner && $user->is_active && ($role !== UserRole::Owner || ! $isActive);

            if ($losesOwner && ! $this->otherActiveOwnerExists($user)) {
                throw ValidationException::withMessages(['user' => __('Debe quedar al menos un dueño activo.')]);
            }

            $user->forceFill(['name' => $name, 'email' => $email, 'role' => $role, 'is_active' => $isActive])->save();

            if (! $isActive) {
                $this->sessions->closeAll($user);
            }

            return $user;
        });
    }

    /**
     * Give the user a new temporary password and log them out of every device.
     */
    public function resetPassword(User $user, string $temporaryPassword): void
    {
        $user->forceFill(['password' => $temporaryPassword, 'must_change_password' => true])->save();

        $this->sessions->closeAll($user);
    }

    private function otherActiveOwnerExists(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->where('role', UserRole::Owner)
            ->where('is_active', true)
            ->lockForUpdate()
            ->exists();
    }
}

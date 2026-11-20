<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('the change password page can be rendered', function () {
    $this->actingAs(User::factory()->withTemporaryPassword()->create())
        ->get(route('password.change'))
        ->assertOk()
        ->assertSee('Cambia tu contraseña');
});

test('it replaces a temporary password and closes the other sessions', function () {
    $user = User::factory()->withTemporaryPassword()->create();
    openSession($user, 'phone-pwa');

    Livewire::actingAs($user)
        ->test('pages::auth.change-password')
        ->set('current_password', 'password')
        ->set('password', 'nueva-contraseña-segura')
        ->set('password_confirmation', 'nueva-contraseña-segura')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->must_change_password)->toBeFalse();
    expect(Hash::check('nueva-contraseña-segura', $user->password))->toBeTrue();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('it keeps the temporary password when the current one is wrong or reused', function (string $current, string $new) {
    $user = User::factory()->withTemporaryPassword()->create();

    Livewire::actingAs($user)
        ->test('pages::auth.change-password')
        ->set('current_password', $current)
        ->set('password', $new)
        ->set('password_confirmation', $new)
        ->call('changePassword')
        ->assertHasErrors();

    expect($user->fresh()->must_change_password)->toBeTrue();
})->with([
    'wrong current password' => ['otra-cosa', 'nueva-contraseña-segura'],
    'same password again' => ['password', 'password'],
]);

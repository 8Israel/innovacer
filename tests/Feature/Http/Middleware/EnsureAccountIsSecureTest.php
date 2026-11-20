<?php

use App\Models\User;

test('it lets a secured account into the application', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

test('it logs out a user who was deactivated while logged in', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('it sends a user with a temporary password to change it first', function (string $route) {
    $user = User::factory()->withTemporaryPassword()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get(route($route))
        ->assertRedirect(route('password.change'));
})->with(['dashboard', 'customers.index', 'security.edit']);

test('it sends a user without two-step verification to set it up', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('it lets a user without two-step verification open the security page to set it up', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();
});

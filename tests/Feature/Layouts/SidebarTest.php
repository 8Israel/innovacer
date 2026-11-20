<?php

use App\Models\CashSession;
use App\Models\User;

test('it shows the register as closed when no session is open', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('Caja cerrada')
        ->assertDontSee('Caja abierta');
});

test('it shows the register as open with its opening time', function () {
    CashSession::factory()->create(['opened_at' => today()->setTime(8, 5)]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('Caja abierta · 08:05');
});

test('it shows the user role in the account menu', function (Closure $user, string $label) {
    $this->actingAs($user())
        ->get(route('dashboard'))
        ->assertSee($label);
})->with([
    'owner' => [fn () => User::factory()->owner()->create(), 'Dueño'],
    'clerk' => [fn () => User::factory()->create(), 'Encargada'],
]);

test('it shows the message of a denied section', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['error' => 'No tienes permiso para entrar a esa sección.'])
        ->get(route('dashboard'))
        ->assertSee('No tienes permiso para entrar a esa sección.');
});

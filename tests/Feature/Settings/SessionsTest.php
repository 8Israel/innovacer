<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the sessions page lists the user devices', function () {
    $user = User::factory()->create();
    openSession($user, 'phone-pwa', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');

    $this->actingAs($user)
        ->get(route('sessions.index'))
        ->assertOk()
        ->assertSee('Safari · iPhone / iPad');
});

test('it closes another device from the sessions page', function () {
    $user = User::factory()->create();
    openSession($user, 'phone-pwa');
    openSession($user, 'old-laptop');

    Livewire::actingAs($user)
        ->test('pages::settings.sessions')
        ->call('close', 'phone-pwa');

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['old-laptop']);
});

test('it closes every other device from the sessions page', function () {
    $user = User::factory()->create();
    openSession($user, 'phone-pwa');
    openSession($user, 'old-laptop');

    Livewire::actingAs($user)
        ->test('pages::settings.sessions')
        ->call('closeOthers');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

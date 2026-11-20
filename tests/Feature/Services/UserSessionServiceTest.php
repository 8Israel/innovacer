<?php

use App\Models\User;
use App\Services\UserSessionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

test('it lists only the user sessions, most recently used first', function () {
    $user = User::factory()->create();
    openSession($user, 'counter-pc', 'Mozilla/5.0 (Windows NT 10.0; Win64) Chrome/120.0 Safari/537.36', minutesAgo: 30);
    openSession($user, 'phone-pwa', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile Safari/537.36', minutesAgo: 1);
    openSession(User::factory()->create(), 'someone-else');

    $sessions = app(UserSessionService::class)->sessionsFor($user, currentSessionId: 'phone-pwa');

    expect($sessions->map(fn (array $session) => Arr::only($session, ['id', 'browser', 'platform', 'is_current']))->all())->toBe([
        ['id' => 'phone-pwa', 'browser' => 'Chrome', 'platform' => 'Android', 'is_current' => true],
        ['id' => 'counter-pc', 'browser' => 'Chrome', 'platform' => 'Windows', 'is_current' => false],
    ]);
});

test('it closes one session without touching the others', function () {
    $user = User::factory()->create();
    openSession($user, 'counter-pc');
    openSession($user, 'phone-pwa');

    $closed = app(UserSessionService::class)->close($user, 'phone-pwa');

    expect($closed)->toBeTrue();
    expect(DB::table('sessions')->pluck('id')->all())->toBe(['counter-pc']);
});

test('it never closes a session of another user', function () {
    $user = User::factory()->create();
    openSession(User::factory()->create(), 'someone-else');

    $closed = app(UserSessionService::class)->close($user, 'someone-else');

    expect($closed)->toBeFalse();
    expect(DB::table('sessions')->count())->toBe(1);
});

test('it closes every other session of the user and keeps the current one', function () {
    $user = User::factory()->create();
    openSession($user, 'counter-pc');
    openSession($user, 'phone-pwa');
    openSession($user, 'old-laptop');
    openSession(User::factory()->create(), 'someone-else');

    $closed = app(UserSessionService::class)->closeOthers($user, 'counter-pc');

    expect($closed)->toBe(2);
    expect(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe(['counter-pc', 'someone-else']);
});

test('it closes all sessions of a user', function () {
    $user = User::factory()->create();
    openSession($user, 'counter-pc');
    openSession($user, 'phone-pwa');

    app(UserSessionService::class)->closeAll($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

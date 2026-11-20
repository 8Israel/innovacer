<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'permission:view_reports'])->get('/_test/reports', fn () => 'Reportes');
    Route::middleware(['web', 'auth', 'permission:manage_settings'])->get('/_test/configuration', fn () => 'Configuración');
});

test('it sends the clerk back to the start screen from owner-only sections', function (string $uri) {
    $this->actingAs(User::factory()->create())
        ->get($uri)
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('error');
})->with(['/_test/reports', '/_test/configuration']);

test('it lets the owner into owner-only sections', function (string $uri) {
    $this->actingAs(User::factory()->owner()->create())
        ->get($uri)
        ->assertOk();
})->with(['/_test/reports', '/_test/configuration']);

test('it answers 403 to a json request without the permission', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/_test/reports')
        ->assertForbidden();
});

test('it denies every permission to an inactive owner', function () {
    $this->actingAs(User::factory()->owner()->inactive()->create())
        ->get('/_test/reports')
        ->assertRedirect(route('pos.index'));
});

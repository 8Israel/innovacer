<?php

use App\Models\User;

test('guests are redirected to login', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users are redirected to the point of sale', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertRedirect(route('pos.index'));
});

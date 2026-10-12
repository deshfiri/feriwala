<?php

use App\Models\User;

/*
 * `/settings` is only a doorway to the profile screen. It is registered with the
 * framework's redirect controller on GET and HEAD alone, because Route::redirect()
 * answers every verb — QUERY included — which Wayfinder's generated TypeScript
 * cannot express and `tsc` rejected.
 */

it('sends a signed-in partner from /settings to their profile', function () {
    $user = User::factory()->withBusinessAccount(fn ($account) => $account->active())->create();

    $this->actingAs($user)->get('/settings')->assertRedirect('/settings/profile');
});

it('answers /settings on GET and HEAD only', function () {
    $methods = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'settings')
        ->methods();

    expect($methods)->toBe(['GET', 'HEAD']);
});

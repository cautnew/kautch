<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('the login screen is rendered by the React starter kit', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('login'));
});

test('administrative routes require a signed-in user and self-registration is disabled', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    expect(Route::has('register'))->toBeFalse();
});

test('the API accepts only authenticated session or token requests', function () {
    $this->getJson('/api/v1/auth/profile')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

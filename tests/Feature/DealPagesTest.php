<?php

use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests from deals to the login page', function () {
    $this->get('/deals')
        ->assertRedirect(route('login'));
});

it('renders the login page for guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

it('renders the deals page for authenticated users', function () {
    $user = authenticateApiUser($this);

    $this->get(route('crm.deals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('deals/index')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.first_name', $user->first_name)
            ->where('users.0.id', $user->id)
            ->where('users.0.first_name', $user->first_name));
});

it('redirects authenticated users away from login', function () {
    authenticateApiUser($this);

    $this->get(route('login'))
        ->assertRedirect(route('crm.deals.index'));
});

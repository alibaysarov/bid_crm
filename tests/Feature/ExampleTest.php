<?php

test('redirects guests from the home page to login', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

test('redirects authenticated users from the home page to deals', function () {
    authenticateApiUser($this);

    $this->get(route('home'))
        ->assertRedirect(route('crm.deals.index'));
});

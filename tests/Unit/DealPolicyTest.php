<?php

use App\Models\Deal;
use App\Models\User;
use App\Policies\DealPolicy;

it('allows authenticated users to perform deal CRUD operations', function () {
    $user = new User;
    $deal = new Deal;
    $policy = new DealPolicy;

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $deal))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->update($user, $deal))->toBeTrue()
        ->and($policy->delete($user, $deal))->toBeTrue();
});

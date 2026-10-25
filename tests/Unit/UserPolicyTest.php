<?php

use App\Models\User;
use App\Policies\UserPolicy;

it('restricts user profile CRUD operations to the profile owner', function () {
    $user = new User;
    $user->setAttribute('id', 1);
    $otherUser = new User;
    $otherUser->setAttribute('id', 2);
    $policy = new UserPolicy;

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $user))->toBeTrue()
        ->and($policy->update($user, $user))->toBeTrue()
        ->and($policy->delete($user, $user))->toBeTrue()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->view($user, $otherUser))->toBeFalse()
        ->and($policy->update($user, $otherUser))->toBeFalse()
        ->and($policy->delete($user, $otherUser))->toBeFalse();
});

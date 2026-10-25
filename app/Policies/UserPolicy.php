<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $profile): bool
    {
        return $user->is($profile);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $profile): bool
    {
        return $user->is($profile);
    }

    public function delete(User $user, User $profile): bool
    {
        return $user->is($profile);
    }
}

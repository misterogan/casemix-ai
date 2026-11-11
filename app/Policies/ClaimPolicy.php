<?php

namespace App\Policies;

use App\Models\Claim;
use App\Models\User;

class ClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('claims.view');
    }

    public function view(User $user, Claim $claim): bool
    {
        return $user->can('claims.view');
    }

    public function create(User $user): bool
    {
        return $user->can('claims.edit');
    }

    public function update(User $user, Claim $claim): bool
    {
        return $user->can('claims.edit') && ! $claim->isLocked();
    }

    public function review(User $user, Claim $claim): bool
    {
        return $user->can('claims.review') && ! $claim->isLocked();
    }

    public function approve(User $user, Claim $claim): bool
    {
        return $user->can('claims.approve') && ! $claim->isLocked();
    }

    public function reopen(User $user, Claim $claim): bool
    {
        return $user->can('claims.reopen') && $claim->isLocked();
    }
}

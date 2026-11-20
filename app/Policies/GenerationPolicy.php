<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    /** Users see only their own generations; admins see everything. */
    public function view(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id || $user->is_admin;
    }
}

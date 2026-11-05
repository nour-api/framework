<?php

namespace NourApi\Framework\Auth;

use Illuminate\Auth\AuthManager as BaseAuthManager;

class AuthManager extends BaseAuthManager
{
    /** Clear cached identities while retaining guards and their request rebind callbacks. */
    public function resetUsers(): void
    {
        foreach ($this->guards as $guard) {
            if (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}

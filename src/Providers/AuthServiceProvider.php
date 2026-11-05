<?php

namespace NourApi\Framework\Providers;

use Illuminate\Auth\AuthServiceProvider as BaseAuthServiceProvider;
use NourApi\Framework\Auth\AuthManager;

class AuthServiceProvider extends BaseAuthServiceProvider
{
    protected function registerAuthenticator()
    {
        $this->app->singleton('auth', fn ($app) => new AuthManager($app));
        $this->app->singleton('auth.driver', fn ($app) => $app['auth']->guard());
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class SanctumServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // A) Alias the middleware so "auth.sanctum" can be used in routes:
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('auth.sanctum', EnsureFrontendRequestsAreStateful::class);

        // B) Optionally push Sanctum’s middleware globally 
        //    if you want session-based checks for all requests:
        // $kernel = $this->app->make(Kernel::class);
        // $kernel->pushMiddleware(EnsureFrontendRequestsAreStateful::class);
    }
}

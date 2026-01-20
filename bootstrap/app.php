<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Middleware\HandleCors;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global middleware
        $middleware->use([
            HandleCors::class, // <- CORS
            // ... (whatever else you use globally)
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Add Sanctum's "stateful" middleware to the API group
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
            'feature'    => \App\Http\Middleware\EnsureFeature::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/*',
        ]);

        // If you use Jetstream's session auth, you can ensure its middleware is in the web group:
        // use Laravel\Jetstream\Http\Middleware\AuthenticateSession;
        // $middleware->appendToGroup('web', AuthenticateSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

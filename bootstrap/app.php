<?php

declare(strict_types=1);

use App\Infrastructure\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Console commands live in the Infrastructure layer, not in app/Console/Commands.
    ->withCommands([__DIR__ . '/../app/Infrastructure/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        // Forwarded headers are trusted only from the TRUSTED_PROXIES setting (empty trusts nobody).
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            // Caps the preload `Link` headers of a page: a safety net against oversized FastCGI response headers, not a budget.
            AddLinkHeadersForPreloadedAssets::using(limit: 25),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

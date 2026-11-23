<?php

declare(strict_types=1);

namespace App\Domain\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service providers live in App\Domain\Providers, the one namespace of the domain that may use the
 * container and facades (LayerDependencyTest exempts it).
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}

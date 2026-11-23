<?php

declare(strict_types=1);

namespace App\Domain\Providers\Filament;

use Filament\Panel;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Support\Facades\LunarPanel;

/**
 * Registers Lunar's own Filament panel (Filament 4) at /admin. Lunar keeps its default theme; a brand
 * logo or render hooks go into the panel callback below. It lives in App\Domain\Providers, the one
 * namespace of the domain that may use the container and facades (LayerDependencyTest exempts it).
 */
final class LunarPanelProvider extends ServiceProvider
{
    public const string PANEL_PATH = 'admin';

    public function register(): void
    {
        LunarPanel::panel(fn (Panel $panel): Panel => $panel->path(self::PANEL_PATH))->register();
    }
}

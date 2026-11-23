<?php

declare(strict_types=1);

use App\Domain\Providers\AppServiceProvider;
use App\Domain\Providers\Filament\LunarPanelProvider;

// Service providers of the application live in App\Domain\Providers. That namespace is the one
// exception to "the domain uses no container, facades or framework": the layer architecture test
// exempts exactly App\Domain\Providers.
//
// This file is owned by the LAST template layer of the variant that touches it: a layer that adds
// a provider overwrites the whole list instead of patching it, so the list stays explicit.
return [
    AppServiceProvider::class,
    LunarPanelProvider::class,
];

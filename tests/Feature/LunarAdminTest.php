<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Providers\Filament\LunarPanelProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LunarAdminTest extends TestCase
{
    public function test_the_lunar_admin_panel_is_registered_at_admin(): void
    {
        self::assertTrue(Route::has('filament.lunar.auth.login'));
        self::assertSame('admin/login', Route::getRoutes()->getByName('filament.lunar.auth.login')?->uri());
    }

    public function test_the_panel_is_registered_by_its_own_provider(): void
    {
        self::assertNotSame([], app()->getProviders(LunarPanelProvider::class));
    }

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_storefront_stays_on_inertia(): void
    {
        $this->get('/')->assertOk()->assertSee('data-page', false);
    }
}

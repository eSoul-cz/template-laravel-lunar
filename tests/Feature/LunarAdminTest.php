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

    /**
     * The panel links its stylesheets, scripts and fonts as files under public/. Nothing serves them
     * when they are not published there (composer's post-autoload-dump runs `filament:upgrade`), and
     * the panel then loads without styles and scripts.
     */
    public function test_the_assets_of_the_admin_login_are_published_files(): void
    {
        $html = (string) $this->get('/admin/login')->assertOk()->getContent();

        preg_match_all('#(?:href|src)="' . preg_quote(url('/'), '#') . '(/(?:css|js|fonts)/[^"?]+)#', $html, $matches);
        $paths = array_values(array_unique($matches[1]));

        self::assertNotSame([], $paths, 'The admin login links no stylesheet or script of the panel.');
        foreach ($paths as $path) {
            self::assertFileExists(public_path($path), "The admin login links {$path}, which is not published.");
        }
    }

    public function test_the_storefront_stays_on_inertia(): void
    {
        $this->get('/')->assertOk()->assertSee('data-page', false);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's local database.
     *
     * This is the general seeder: synthetic development data (factories, a development account). It is for
     * local development only and refuses to run anywhere else, so `php artisan db:seed` without `--class`
     * fails in staging and production. The production start script never seeds. What every environment
     * needs belongs in a separate `ProductionSeeder` (idempotent, create-only, no account), and deliberate
     * demo content in a `DemoSeeder` (no account with credentials outside local development); a developer
     * runs either by hand in the container (`php artisan db:seed --class=ProductionSeeder --force`), and
     * this seeder MAY call them so that local data has their rows too.
     */
    public function run(): void
    {
        if ( ! app()->environment('local', 'testing')) {
            throw new RuntimeException('DatabaseSeeder seeds synthetic development data: it runs only in the local and testing environments. Run a production or demo seeder by hand with --class.');
        }

        if (User::query()->where('email', 'test@example.com')->doesntExist()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}

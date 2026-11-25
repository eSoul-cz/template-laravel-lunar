<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's local database.
     *
     * This is the local seeder: it calls the demo seeders (factories, invented data), which refuse to run
     * outside `local` and `testing`. The production start script never seeds. What every environment needs
     * belongs in a separate `ProductionSeeder` (idempotent, create-only, no account) that a developer runs by
     * hand in the container (`php artisan db:seed --class=ProductionSeeder --force`); this seeder MAY call it.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}

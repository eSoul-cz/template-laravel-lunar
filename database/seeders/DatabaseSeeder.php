<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The production start script does not seed. A seeder called here from outside the local check
     * creates only what every environment needs, is idempotent and never creates an account.
     */
    public function run(): void
    {
        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}

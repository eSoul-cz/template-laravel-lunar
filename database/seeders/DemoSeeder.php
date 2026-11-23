<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data for local development only: DatabaseSeeder calls it when the application runs in the `local`
 * environment. It creates an account with the factory password, so it never runs anywhere else.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('email', 'test@example.com')->doesntExist()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}

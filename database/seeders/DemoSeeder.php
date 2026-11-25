<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data for local development only: it uses factories (invented data) and creates an account with the factory
 * password, so it refuses to run outside the `local` and `testing` environments.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if ( ! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder uses factories and invented data: it runs only in the local and testing environments.');
        }

        if (User::query()->where('email', 'test@example.com')->doesntExist()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}

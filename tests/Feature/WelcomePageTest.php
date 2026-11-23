<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class WelcomePageTest extends TestCase
{
    public function test_the_welcome_page_renders_through_inertia(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Welcome')
                    ->where('name', config('app.name')),
            );
    }
}

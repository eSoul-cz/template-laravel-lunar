<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

/**
 * Shared base class of the feature tests. Boots Laravel, refuses HTTP requests nobody faked and
 * gives every test a rolled-back transaction on a database that is migrated once per worker, and
 * only for tests that touch the database. Unit tests extend PHPUnit\Framework\TestCase instead.
 */
abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // The PHP tests run before the assets are built, so there is no Vite manifest to read.
        $this->withoutVite();
    }
}

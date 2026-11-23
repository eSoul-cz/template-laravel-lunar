<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

final class TrustProxiesTest extends TestCase
{
    private const array FORWARDED = [
        'X-Forwarded-For' => '203.0.113.9',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'forwarded.example',
        'X-Forwarded-Port' => '443',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_request-probe', static fn (Request $request): array => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        config()->set('proxy.trusted', '');

        $this->probe('10.0.0.5')->assertOk()->assertExactJson(['ip' => '10.0.0.5', 'secure' => false, 'host' => 'localhost']);
    }

    public function test_forwarded_headers_from_a_trusted_proxy_are_used(): void
    {
        config()->set('proxy.trusted', '192.0.2.1, 10.0.0.0/8');

        $this->probe('10.0.0.5')->assertOk()->assertExactJson(['ip' => '203.0.113.9', 'secure' => true, 'host' => 'forwarded.example']);
    }

    public function test_forwarded_headers_from_any_other_address_are_ignored(): void
    {
        config()->set('proxy.trusted', '10.0.0.0/8');

        $this->probe('198.51.100.7')->assertOk()->assertExactJson(['ip' => '198.51.100.7', 'secure' => false, 'host' => 'localhost']);
    }

    public function test_a_wildcard_is_rejected(): void
    {
        config()->set('proxy.trusted', '*');

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        $this->probe('10.0.0.5');
    }

    private function probe(string $remoteAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withHeaders(self::FORWARDED)
            ->get('/_request-probe');
    }
}

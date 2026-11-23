<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use InvalidArgumentException;
use Override;

/**
 * Trusts the forwarded headers (scheme, host, client address) only from the proxies listed in the
 * TRUSTED_PROXIES setting (`config('proxy.trusted')`, addresses or CIDR ranges separated by commas).
 * An empty list trusts nobody: behind the host NGINX the scheme and the client address arrive as
 * FastCGI parameters, not as forwarded headers. `*` is never accepted.
 *
 * It replaces the framework middleware in bootstrap/app.php; the framework one reads the
 * environment through `config('trustedproxy.proxies')`, which this project does not define.
 */
final class TrustProxies extends BaseTrustProxies
{
    /** Values that make the framework trust every caller. */
    private const array EVERYONE = ['*', '**', 'REMOTE_ADDR'];

    /** @return list<string> */
    #[Override]
    protected function proxies(): array
    {
        $configured = config('proxy.trusted');
        $proxies = is_string($configured) ? array_map(trim(...), explode(',', $configured)) : [];
        $proxies = array_values(array_filter($proxies, static fn (string $proxy): bool => $proxy !== ''));

        foreach ($proxies as $proxy) {
            if (in_array($proxy, self::EVERYONE, true)) {
                throw new InvalidArgumentException('TRUSTED_PROXIES must list the addresses of the proxies, never "' . $proxy . '".');
            }
        }

        return $proxies;
    }
}

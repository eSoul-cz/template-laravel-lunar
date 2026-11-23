<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Addresses or CIDR ranges, separated by commas, of the reverse proxies whose
    | forwarded headers (scheme, host, client address) the application trusts.
    | Empty trusts none. `*` is not accepted. Read by
    | App\Infrastructure\Http\Middleware\TrustProxies.
    |
    */
    'trusted' => env('TRUSTED_PROXIES', ''),
];

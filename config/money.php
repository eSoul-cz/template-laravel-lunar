<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | The ISO 4217 code (a case of App\Domain\ValueObjects\Currency) that
    | App\Infrastructure\Casts\MoneyCast uses when a row has no currency column
    | value, and the only currency of a column cast with `MoneyCast::class . ':config'`.
    |
    */
    'default_currency' => env('MONEY_DEFAULT_CURRENCY', 'CZK'),
];

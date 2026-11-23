<?php

declare(strict_types=1);

use App\Infrastructure\Lunar\Cart\CalculateLines;
use Lunar\Pipelines\Cart\ApplyDiscounts;
use Lunar\Pipelines\Cart\ApplyShipping;
use Lunar\Pipelines\Cart\Calculate;
use Lunar\Pipelines\Cart\CalculateShippingSubTotal;
use Lunar\Pipelines\Cart\CalculateTax;
use Lunar\Pipelines\CartLine\GetUnitPrice;

/*
|--------------------------------------------------------------------------
| Lunar cart configuration
|--------------------------------------------------------------------------
|
| Lunar merges this file over its own `cart` config one top-level key at a
| time, so only `pipelines` is set here; everything else stays Lunar's default.
|
| `cart` is Lunar's default pipeline with one step replaced: Lunar's
| CalculateLines multiplies the unit price as a float and truncates it, the
| project step uses integer minor units and the application's rounding rule
| (Money::ROUNDING, half away from zero). CartPipelineConfigTest pins this list
| to Lunar's default, so a Lunar update that changes the steps fails the test.
|
*/
return [
    'pipelines' => [
        'cart' => [
            CalculateLines::class,
            ApplyShipping::class,
            CalculateShippingSubTotal::class,
            ApplyDiscounts::class,
            CalculateTax::class,
            Calculate::class,
        ],

        'cart_lines' => [
            GetUnitPrice::class,
        ],
    ],
];

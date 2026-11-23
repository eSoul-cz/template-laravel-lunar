<?php

declare(strict_types=1);

namespace Tests\Feature\Lunar;

use App\Infrastructure\Lunar\Cart\CalculateLines;
use Lunar\Pipelines\Cart\CalculateLines as LunarCalculateLines;
use Tests\TestCase;

final class CartPipelineConfigTest extends TestCase
{
    public function test_the_cart_pipeline_is_lunars_default_with_only_calculate_lines_replaced(): void
    {
        $default = require base_path('vendor/lunarphp/core/config/cart.php');

        self::assertIsArray($default);
        self::assertIsArray($default['pipelines']);

        $expected = array_map(
            static fn (mixed $step): mixed => $step === LunarCalculateLines::class ? CalculateLines::class : $step,
            $default['pipelines']['cart'],
        );

        self::assertSame($expected, config('lunar.cart.pipelines.cart'));
        self::assertSame($default['pipelines']['cart_lines'], config('lunar.cart.pipelines.cart_lines'));
    }

    public function test_the_rest_of_the_cart_config_stays_lunars_default(): void
    {
        $default = require base_path('vendor/lunarphp/core/config/cart.php');

        self::assertIsArray($default);
        self::assertSame($default['actions'], config('lunar.cart.actions'));
        self::assertSame($default['validators'], config('lunar.cart.validators'));
    }
}

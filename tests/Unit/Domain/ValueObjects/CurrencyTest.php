<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\Currency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function test_every_currency_has_an_exponent_and_a_symbol(): void
    {
        foreach (Currency::cases() as $currency) {
            self::assertContains($currency->exponent(), [0, 2, 3], $currency->value);
            self::assertNotSame('', $currency->symbol(), $currency->value);
        }
    }

    public function test_the_yen_has_no_minor_unit(): void
    {
        self::assertSame(0, Currency::JPY->exponent());
        self::assertSame(2, Currency::EUR->exponent());
    }

    public function test_it_is_backed_by_the_iso_code(): void
    {
        self::assertSame(Currency::CZK, Currency::from('CZK'));
        self::assertNull(Currency::tryFrom('XXX'));
    }
}

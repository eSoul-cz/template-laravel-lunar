<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\Currency;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\MoneyFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    /** @return iterable<string, array{string, int, Currency, string}> */
    public static function formats(): iterable
    {
        yield 'cs groups with a no-break space and puts the symbol last' => ['cs', 123_456_789, Currency::CZK, "1\u{00A0}234\u{00A0}567,89\u{00A0}Kč"];
        yield 'cs below one thousand' => ['cs', 99_950, Currency::CZK, "999,50\u{00A0}Kč"];
        yield 'cs below one unit' => ['cs', 5, Currency::EUR, "0,05\u{00A0}€"];
        yield 'cs negative' => ['cs', -150_000, Currency::EUR, "-1\u{00A0}500,00\u{00A0}€"];
        yield 'cs zero' => ['cs', 0, Currency::CZK, "0,00\u{00A0}Kč"];
        yield 'sk is the same as cs' => ['sk', 123_456, Currency::EUR, "1\u{00A0}234,56\u{00A0}€"];
        yield 'de uses dots to group' => ['de', 123_456_789, Currency::EUR, "1.234.567,89\u{00A0}€"];
        yield 'en puts the symbol first' => ['en', 123_456_789, Currency::USD, '$1,234,567.89'];
        yield 'en negative' => ['en', -5, Currency::GBP, '-£0.05'];
        yield 'a currency without minor units' => ['en', 1_234_567, Currency::JPY, '¥1,234,567'];
        yield 'exactly three digits' => ['en', 100_000, Currency::EUR, '€1,000.00'];
        yield 'the largest amount' => ['en', PHP_INT_MAX, Currency::EUR, '€92,233,720,368,547,758.07'];
        yield 'the smallest amount' => ['en', PHP_INT_MIN, Currency::EUR, '-€92,233,720,368,547,758.08'];
    }

    #[DataProvider('formats')]
    public function test_it_formats_from_integer_parts(string $locale, int $minor, Currency $currency, string $expected): void
    {
        self::assertSame($expected, new MoneyFormatter($locale)->format(Money::of($minor, $currency)));
    }

    public function test_czech_is_the_default_locale(): void
    {
        self::assertSame("1,00\u{00A0}Kč", new MoneyFormatter()->format(Money::of(100, Currency::CZK)));
    }

    public function test_it_refuses_a_locale_without_a_row(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MoneyFormatter('xx');
    }
}

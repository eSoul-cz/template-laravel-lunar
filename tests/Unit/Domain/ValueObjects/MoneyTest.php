<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\Currency;
use App\Domain\ValueObjects\CurrencyMismatchException;
use App\Domain\ValueObjects\Money;
use ArithmeticError;
use BcMath\Number;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoundingMode;
use ValueError;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, Currency, int}> */
    public static function decimalStrings(): iterable
    {
        yield 'two digits' => ['1234.50', Currency::EUR, 123_450];
        yield 'whole amount' => ['12', Currency::CZK, 1_200];
        yield 'one fraction digit' => ['0.5', Currency::USD, 50];
        yield 'negative' => ['-0.05', Currency::EUR, -5];
        yield 'zero exponent' => ['1500', Currency::JPY, 1_500];
        yield 'trailing zeros within the exponent' => ['1.20', Currency::EUR, 120];
        yield 'the largest amount' => ['92233720368547758.07', Currency::EUR, PHP_INT_MAX];
    }

    /** @return iterable<string, array{string, Currency}> */
    public static function invalidDecimalStrings(): iterable
    {
        yield 'a third fraction digit' => ['1.001', Currency::EUR];
        yield 'fraction digits on a zero exponent currency' => ['1.5', Currency::JPY];
        yield 'a thousands separator' => ['1,234.50', Currency::EUR];
        yield 'an exponent' => ['1e3', Currency::EUR];
        yield 'empty' => ['', Currency::EUR];
        yield 'a space' => [' 1.00', Currency::EUR];
    }

    /** @return iterable<string, array{int, Currency, string}> */
    public static function decimalOutput(): iterable
    {
        yield 'cents' => [123_450, Currency::EUR, '1234.50'];
        yield 'less than one unit' => [5, Currency::EUR, '0.05'];
        yield 'zero' => [0, Currency::CZK, '0.00'];
        yield 'negative' => [-5, Currency::EUR, '-0.05'];
        yield 'negative whole' => [-1_200, Currency::CZK, '-12.00'];
        yield 'zero exponent' => [1_500, Currency::JPY, '1500'];
        yield 'smallest integer' => [PHP_INT_MIN, Currency::EUR, '-92233720368547758.08'];
    }

    /** @return iterable<string, array{int, int|string|Number, int}> */
    public static function products(): iterable
    {
        yield 'an integer quantity' => [1_999, 3, 5_997];
        yield 'a VAT rate as a decimal string' => [10_000, '1.21', 12_100];
        yield 'rounds half away from zero upwards' => [5, '0.5', 3];
        yield 'rounds half away from zero downwards' => [-5, '0.5', -3];
        yield 'rounds below half down' => [10, '0.24', 2];
        yield 'rounds above half up' => [10, '0.26', 3];
        yield 'a Number factor' => [3_333, new Number('0.15'), 500];
        yield 'zero' => [1_999, 0, 0];
    }

    /** @return iterable<string, array{int, list<int>, list<int>}> */
    public static function allocations(): iterable
    {
        yield 'three equal parts' => [100, [1, 1, 1], [34, 33, 33]];
        yield 'proportional' => [100, [70, 30], [70, 30]];
        yield 'the leftover goes to the first parts' => [5, [3, 7], [2, 3]];
        yield 'one part' => [99, [5], [99]];
        yield 'a zero ratio gets nothing' => [10, [1, 0, 1], [5, 0, 5]];
        yield 'a negative amount' => [-100, [1, 1, 1], [-34, -33, -33]];
        yield 'zero' => [0, [1, 2], [0, 0]];
        yield 'fewer units than parts' => [2, [1, 1, 1], [1, 1, 0]];
        yield 'a large amount' => [PHP_INT_MAX, [1, 1], [4_611_686_018_427_387_904, 4_611_686_018_427_387_903]];
    }

    /** @return iterable<string, array{list<int>}> */
    public static function invalidRatios(): iterable
    {
        yield 'none' => [[]];
        yield 'a negative ratio' => [[1, -1]];
        yield 'all zero' => [[0, 0]];
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function proportions(): iterable
    {
        yield 'an exact quotient' => [1_000, 3, 2, 1_500];
        yield 'the unit quantity divides the amount' => [1_999, 100, 100, 1_999];
        yield 'a tie rounds away from zero' => [5, 1, 2, 3];
        yield 'a negative tie rounds away from zero' => [-5, 1, 2, -3];
        yield 'below a tie rounds down' => [10, 1, 3, 3];
        yield 'above a tie rounds up' => [20, 1, 3, 7];
        yield 'a large product does not overflow' => [PHP_INT_MAX, 2, 4, 4_611_686_018_427_387_904];
    }

    public function test_it_holds_integer_minor_units_and_a_currency(): void
    {
        $money = Money::of(12_345, Currency::EUR);

        self::assertSame(12_345, $money->minor);
        self::assertSame(Currency::EUR, $money->currency);
        self::assertTrue(Money::zero(Currency::CZK)->isZero());
    }

    #[DataProvider('decimalStrings')]
    public function test_it_converts_a_decimal_string_exactly(string $amount, Currency $currency, int $minor): void
    {
        self::assertSame($minor, Money::fromDecimalString($amount, $currency)->minor);
    }

    #[DataProvider('invalidDecimalStrings')]
    public function test_it_refuses_what_is_not_an_exact_amount(string $amount, Currency $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString($amount, $currency);
    }

    public function test_it_refuses_an_amount_that_does_not_fit_an_integer(): void
    {
        $this->expectException(ArithmeticError::class);

        Money::fromDecimalString('92233720368547758.08', Currency::EUR);
    }

    #[DataProvider('decimalOutput')]
    public function test_it_writes_the_exact_decimal_string(int $minor, Currency $currency, string $expected): void
    {
        self::assertSame($expected, Money::of($minor, $currency)->toDecimalString());
    }

    public function test_it_adds_and_subtracts_within_one_currency(): void
    {
        $ten = Money::of(1_000, Currency::EUR);
        $three = Money::of(300, Currency::EUR);

        self::assertSame(1_300, $ten->add($three)->minor);
        self::assertSame(700, $ten->subtract($three)->minor);
        self::assertSame(-700, $three->subtract($ten)->minor);
        self::assertSame(1_000, $ten->minor, 'The operands stay unchanged.');
    }

    public function test_it_refuses_to_combine_currencies(): void
    {
        try {
            Money::of(100, Currency::EUR)->add(Money::of(100, Currency::CZK));
            self::fail('Adding two currencies must fail.');
        } catch (CurrencyMismatchException $exception) {
            self::assertSame(Currency::EUR, $exception->expected);
            self::assertSame(Currency::CZK, $exception->actual);
        }
    }

    public function test_it_refuses_to_compare_currencies(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of(100, Currency::EUR)->lessThan(Money::of(100, Currency::CZK));
    }

    public function test_it_fails_instead_of_overflowing(): void
    {
        $this->expectException(ArithmeticError::class);

        Money::of(PHP_INT_MAX, Currency::EUR)->add(Money::of(1, Currency::EUR));
    }

    public function test_it_fails_when_subtracting_below_the_smallest_integer(): void
    {
        $this->expectException(ArithmeticError::class);

        Money::of(PHP_INT_MIN, Currency::EUR)->subtract(Money::of(1, Currency::EUR));
    }

    public function test_it_fails_when_negating_the_smallest_integer(): void
    {
        $this->expectException(ArithmeticError::class);

        Money::of(PHP_INT_MIN, Currency::EUR)->negate();
    }

    public function test_it_negates_and_takes_the_absolute_value(): void
    {
        self::assertSame(-500, Money::of(500, Currency::EUR)->negate()->minor);
        self::assertSame(500, Money::of(-500, Currency::EUR)->abs()->minor);
        self::assertSame(500, Money::of(500, Currency::EUR)->abs()->minor);
    }

    #[DataProvider('products')]
    public function test_it_multiplies_by_an_exact_factor_and_rounds_once(int $minor, int|string|Number $factor, int $expected): void
    {
        self::assertSame($expected, Money::of($minor, Currency::EUR)->multiply($factor)->minor);
    }

    public function test_the_application_rounding_mode_is_half_away_from_zero(): void
    {
        self::assertSame(RoundingMode::HalfAwayFromZero, Money::ROUNDING);
    }

    public function test_a_caller_can_pass_another_rounding_mode_explicitly(): void
    {
        self::assertSame(2, Money::of(5, Currency::EUR)->multiply('0.5', RoundingMode::HalfEven)->minor);
        self::assertSame(2, Money::of(5, Currency::EUR)->multiply('0.5', RoundingMode::HalfTowardsZero)->minor);
        self::assertSame(3, Money::of(5, Currency::EUR)->multiply('0.5', RoundingMode::PositiveInfinity)->minor);
    }

    public function test_multiplying_fails_when_the_product_does_not_fit_an_integer(): void
    {
        $this->expectException(ArithmeticError::class);

        Money::of(PHP_INT_MAX, Currency::EUR)->multiply(2);
    }

    #[DataProvider('proportions')]
    public function test_it_takes_an_exact_fraction_and_rounds_once(int $minor, int $numerator, int $denominator, int $expected): void
    {
        self::assertSame($expected, Money::of($minor, Currency::EUR)->proportion($numerator, $denominator)->minor);
    }

    public function test_a_proportion_needs_a_positive_denominator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, Currency::EUR)->proportion(1, 0);
    }

    public function test_it_refuses_a_factor_that_is_not_a_number(): void
    {
        $this->expectException(ValueError::class);

        Money::of(100, Currency::EUR)->multiply('twenty');
    }

    /**
     * @param  list<int>  $ratios
     * @param  list<int>  $expected
     */
    #[DataProvider('allocations')]
    public function test_it_allocates_without_losing_a_minor_unit(int $minor, array $ratios, array $expected): void
    {
        $parts = Money::of($minor, Currency::EUR)->allocate($ratios);

        self::assertSame($expected, array_map(static fn (Money $part): int => $part->minor, $parts));
        self::assertSame($minor, array_sum(array_map(static fn (Money $part): int => $part->minor, $parts)));

        foreach ($parts as $part) {
            self::assertSame(Currency::EUR, $part->currency);
        }
    }

    /** @param  list<int>  $ratios */
    #[DataProvider('invalidRatios')]
    public function test_it_refuses_unusable_ratios(array $ratios): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, Currency::EUR)->allocate($ratios);
    }

    public function test_it_compares_amounts(): void
    {
        $one = Money::of(100, Currency::EUR);
        $two = Money::of(200, Currency::EUR);

        self::assertSame(-1, $one->compare($two));
        self::assertSame(1, $two->compare($one));
        self::assertSame(0, $one->compare(Money::of(100, Currency::EUR)));
        self::assertTrue($two->greaterThan($one));
        self::assertFalse($one->greaterThan($one));
        self::assertTrue($one->greaterThanOrEqual($one));
        self::assertTrue($one->lessThan($two));
        self::assertFalse($one->lessThan($one));
        self::assertTrue($one->lessThanOrEqual($one));
        self::assertTrue($one->equals(Money::of(100, Currency::EUR)));
        self::assertFalse($one->equals($two));
    }

    public function test_equality_includes_the_currency_without_failing(): void
    {
        self::assertFalse(Money::of(100, Currency::EUR)->equals(Money::of(100, Currency::CZK)));
    }

    public function test_it_reports_its_sign(): void
    {
        self::assertTrue(Money::of(1, Currency::EUR)->isPositive());
        self::assertFalse(Money::of(1, Currency::EUR)->isNegative());
        self::assertTrue(Money::of(-1, Currency::EUR)->isNegative());
        self::assertFalse(Money::of(-1, Currency::EUR)->isPositive());
        self::assertFalse(Money::of(0, Currency::EUR)->isPositive());
        self::assertFalse(Money::of(0, Currency::EUR)->isNegative());
    }
}

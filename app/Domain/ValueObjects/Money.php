<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use ArithmeticError;
use BcMath\Number;
use InvalidArgumentException;
use RoundingMode;

/**
 * An amount of money: an integer in minor units (cents) and its currency. Immutable.
 *
 * Why an own class and not a library (the dependencies guideline prefers a maintained library):
 * the rules of the money guideline are small and strict - integer minor units, arithmetic only within
 * one currency, one rounding mode applied in one place, no float anywhere, not even for display - and
 * a general-purpose library offers float and `numeric-string` entry points that those rules forbid, so
 * it would have to be wrapped in the same checks. About 200 lines are cheaper than the wrapper. A
 * project that prefers `moneyphp/money` keeps this API and wraps the library behind it.
 *
 * Rounding: `multiply()` is the only operation that rounds, and it rounds once, with
 * `Money::ROUNDING`. The mode is one per application (half away from zero by default): a project that
 * needs another, for example half-even for a tax rule, changes the constant and records the reason in
 * its money ADR; call sites do not choose a mode. Rates and coefficients are decimal strings or
 * `BcMath\Number`, never floats.
 */
final readonly class Money
{
    /** The rounding mode of the application, applied by `multiply()`. */
    public const RoundingMode ROUNDING = RoundingMode::HalfAwayFromZero;

    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {}

    public static function of(int $minor, Currency $currency): self
    {
        return new self($minor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * An amount written in major units as a decimal string ("1234.50", "-0.5"). The only place that
     * turns major units into minor ones; more fraction digits than the currency has are an error, the
     * amount is never rounded silently.
     */
    public static function fromDecimalString(string $amount, Currency $currency): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1 || ! is_numeric($amount)) {
            throw new InvalidArgumentException('An amount is a decimal string such as "1234.50".');
        }

        $scaled = new Number($amount)->mul(10 ** $currency->exponent());

        if ($scaled->round(0)->compare($scaled) !== 0) {
            throw new InvalidArgumentException('The amount has more fraction digits than the currency has.');
        }

        return new self(self::toMinor($scaled), $currency);
    }

    /** The amount in major units as an exact decimal string with the currency's number of digits ("1234.50"). */
    public function toDecimalString(): string
    {
        $exponent = $this->currency->exponent();
        $digits = str_pad(ltrim((string) $this->minor, '-'), $exponent + 1, '0', STR_PAD_LEFT);
        $sign = $this->minor < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign . $digits;
        }

        return $sign . substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedAdd($this->minor, $other->minor), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedAdd($this->minor, self::checkedNegate($other->minor)), $this->currency);
    }

    public function negate(): self
    {
        return new self(self::checkedNegate($this->minor), $this->currency);
    }

    public function abs(): self
    {
        return $this->minor < 0 ? $this->negate() : $this;
    }

    /**
     * The amount times an exact factor (a rate or a quantity: an integer, a decimal string or a Number),
     * rounded once to whole minor units with `Money::ROUNDING`.
     *
     * @param  int|numeric-string|Number  $factor
     */
    public function multiply(int|string|Number $factor, RoundingMode $rounding = self::ROUNDING): self
    {
        $product = new Number($this->minor)->mul($factor)->round(0, $rounding);

        return new self(self::toMinor($product), $this->currency);
    }

    /**
     * The amount times an exact fraction (`$numerator / $denominator`, for example a quantity of a pack
     * price that covers several units), rounded once to whole minor units with `Money::ROUNDING`. The
     * division keeps 40 digits, far more than two 64-bit integers can produce, so the rounding sees the
     * exact quotient.
     */
    public function proportion(int $numerator, int $denominator, RoundingMode $rounding = self::ROUNDING): self
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator of a proportion is positive.');
        }

        $quotient = new Number($this->minor)->mul($numerator)->div($denominator, 40)->round(0, $rounding);

        return new self(self::toMinor($quotient), $this->currency);
    }

    /**
     * Splits the amount in proportion to the ratios without losing or creating a minor unit: every part
     * is the floor of its share and the leftover units go one each to the first parts. The parts always
     * add up to this amount. A negative amount is split as its absolute value and negated.
     *
     * @param  list<int>  $ratios  non-negative integers with a positive sum
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Allocating needs at least one ratio.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('A ratio is not negative.');
            }
        }

        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('The ratios need a positive sum.');
        }

        $magnitude = $this->abs()->minor;
        $shares = array_map(
            static fn (int $ratio): int => self::toMinor(new Number($magnitude)->mul($ratio)->div($total, 0)),
            $ratios,
        );

        $remainder = $magnitude - array_sum($shares);

        foreach (array_keys($shares) as $index) {
            if ($remainder === 0) {
                break;
            }

            if ($ratios[$index] > 0) {
                $shares[$index]++;
                $remainder--;
            }
        }

        $sign = $this->minor < 0 ? -1 : 1;

        return array_map(fn (int $share): self => new self($sign * $share, $this->currency), $shares);
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /** `$a + $b` for integers, failing instead of silently turning into a float on overflow. */
    private static function checkedAdd(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new ArithmeticError('The amount is out of range.');
        }

        return $a + $b;
    }

    private static function checkedNegate(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new ArithmeticError('The amount is out of range.');
        }

        return -$value;
    }

    /** A whole-number Number as an int, failing when it does not fit. */
    private static function toMinor(Number $value): int
    {
        if ($value->compare(PHP_INT_MAX) > 0 || $value->compare(PHP_INT_MIN) < 0) {
            throw new ArithmeticError('The amount is out of range.');
        }

        return (int) (string) $value->round(0);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException($this->currency, $other->currency);
        }
    }
}

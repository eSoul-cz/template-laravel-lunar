<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * The currencies the company works in, by ISO 4217 code, with the number of minor-unit digits
 * (the exponent) and the symbol the formatter prints. Add a case when a project needs another
 * currency; never write a conversion by hand, Money converts through `exponent()`.
 */
enum Currency: string
{
    case CHF = 'CHF';
    case CZK = 'CZK';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case HUF = 'HUF';
    case JPY = 'JPY';
    case PLN = 'PLN';
    case USD = 'USD';

    /** Digits after the decimal separator in the minor unit: 2 for 1 EUR = 100 cents, 0 for the yen. */
    public function exponent(): int
    {
        return match ($this) {
            self::JPY => 0,
            self::CHF, self::CZK, self::EUR, self::GBP, self::HUF, self::PLN, self::USD => 2,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::CHF => 'CHF',
            self::CZK => 'Kč',
            self::EUR => '€',
            self::GBP => '£',
            self::HUF => 'Ft',
            self::JPY => '¥',
            self::PLN => 'zł',
            self::USD => '$',
        };
    }
}

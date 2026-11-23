<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Formats an amount for a person: grouped digits, the locale's decimal separator and the currency
 * symbol. The string is built from the integer and the decimal-string parts of the amount; the
 * amount never passes through a float (not even through ICU's `NumberFormatter::formatCurrency()`),
 * and a formatted string is never parsed back.
 *
 * The separators and the symbol position are a small table of the locales the company ships
 * (`cs`, `sk`, `de`, `en`). A project that needs another locale adds a row.
 */
final readonly class MoneyFormatter
{
    private const string NO_BREAK_SPACE = "\u{00A0}";

    /** @var array<string, array{group: string, decimal: string, symbolFirst: bool}> */
    private const array LOCALES = [
        'cs' => ['group' => self::NO_BREAK_SPACE, 'decimal' => ',', 'symbolFirst' => false],
        'sk' => ['group' => self::NO_BREAK_SPACE, 'decimal' => ',', 'symbolFirst' => false],
        'de' => ['group' => '.', 'decimal' => ',', 'symbolFirst' => false],
        'en' => ['group' => ',', 'decimal' => '.', 'symbolFirst' => true],
    ];

    public function __construct(private string $locale = 'cs')
    {
        if ( ! isset(self::LOCALES[$locale])) {
            throw new InvalidArgumentException('The money formatter has no row for the locale "' . $locale . '".');
        }
    }

    public function format(Money $money): string
    {
        $rules = self::LOCALES[$this->locale];

        [$whole, $fraction] = array_pad(explode('.', ltrim($money->toDecimalString(), '-'), 2), 2, '');

        $number = implode($rules['group'], array_reverse(array_map(
            strrev(...),
            str_split(strrev($whole), 3),
        )));

        if ($fraction !== '') {
            $number .= $rules['decimal'] . $fraction;
        }

        $sign = $money->isNegative() ? '-' : '';
        $symbol = $money->currency->symbol();

        return $rules['symbolFirst']
            ? $sign . $symbol . $number
            : $sign . $number . self::NO_BREAK_SPACE . $symbol;
    }
}

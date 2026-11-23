<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

/** Arithmetic and comparison are defined only within one currency; a conversion is a separate, explicit step. */
final class CurrencyMismatchException extends InvalidArgumentException
{
    public function __construct(
        public readonly Currency $expected,
        public readonly Currency $actual,
    ) {
        parent::__construct('Amounts in different currencies cannot be combined.');
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Casts;

use App\Domain\ValueObjects\Currency;
use App\Domain\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Override;

/**
 * Maps an integer minor-unit `BIGINT` column to a Money value object.
 *
 *  - `'price' => MoneyCast::class`: the currency is stored beside the amount in the sibling column
 *    `price_currency` (`<attribute>_currency`, a 3-letter code). A row without a currency there uses
 *    `config('money.default_currency')`.
 *  - `'price' => MoneyCast::class . ':config'`: the table has no currency column; every amount is in
 *    `config('money.default_currency')` and a Money in another currency is refused.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final readonly class MoneyCast implements CastsAttributes
{
    public const string CURRENCY_FROM_CONFIG = 'config';

    public function __construct(private string $currencySource = '') {}

    /** @param  array<string, mixed>  $attributes */
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $minor = filter_var($value, FILTER_VALIDATE_INT);

        if ($minor === false) {
            throw new InvalidArgumentException('The column "' . $key . '" does not hold an integer amount in minor units.');
        }

        return Money::of($minor, $this->currencyOf($key, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ( ! $value instanceof Money) {
            throw new InvalidArgumentException('The attribute "' . $key . '" takes a Money object.');
        }

        if ($this->currencySource === self::CURRENCY_FROM_CONFIG) {
            if ($value->currency !== self::defaultCurrency()) {
                throw new InvalidArgumentException('The attribute "' . $key . '" holds only ' . self::defaultCurrency()->value . ' (config money.default_currency).');
            }

            return [$key => $value->minor];
        }

        return [$key => $value->minor, $key . '_currency' => $value->currency->value];
    }

    private static function defaultCurrency(): Currency
    {
        $code = config('money.default_currency');

        return Currency::from(is_string($code) ? $code : '');
    }

    /** @param  array<string, mixed>  $attributes */
    private function currencyOf(string $key, array $attributes): Currency
    {
        if ($this->currencySource === self::CURRENCY_FROM_CONFIG) {
            return self::defaultCurrency();
        }

        $code = $attributes[$key . '_currency'] ?? null;

        return is_string($code) && $code !== '' ? Currency::from($code) : self::defaultCurrency();
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Lunar\Cart;

use App\Domain\ValueObjects\Currency;
use App\Domain\ValueObjects\Money;
use Closure;
use Illuminate\Pipeline\Pipeline;
use LogicException;
use Lunar\DataTypes\Price;
use Lunar\Models\Cart;
use Lunar\Models\CartLine;
use Lunar\Models\Contracts\Cart as CartContract;
use Lunar\Models\Currency as CurrencyModel;

/**
 * The first step of Lunar's cart pipeline, replacing Lunar's CalculateLines (config/lunar/cart.php).
 *
 * It does what Lunar's step does, with the money rules of the guidelines: a line's sub total is the
 * unit price times the quantity in integer minor units (`value * quantity / unitQty`), rounded once
 * with Money::ROUNDING (half away from zero). Lunar's step computes it with floats and truncates the
 * result, which differs only when `value * quantity` is not a multiple of the unit quantity.
 */
final class CalculateLines
{
    /**
     * @param  Closure(CartContract): mixed  $next
     */
    public function handle(CartContract $cart, Closure $next): mixed
    {
        assert($cart instanceof Cart);

        $currencyModel = $cart->currency;
        assert($currencyModel instanceof CurrencyModel);

        $currency = Currency::tryFrom($currencyModel->code)
            ?? throw new LogicException('Add the currency ' . $currencyModel->code . ' to App\Domain\ValueObjects\Currency.');

        foreach ($cart->lines as $line) {
            assert($line instanceof CartLine);

            $cartLine = app(Pipeline::class)
                ->send($line)
                ->through(config('lunar.cart.pipelines.cart_lines', []))
                // Lunar's step passes a closure that calls cacheProperties(); Laravel 13's thenReturn() takes no argument, so it never ran.
                ->thenReturn();
            assert($cartLine instanceof CartLine);

            $unitPrice = $cartLine->unitPrice ?? throw new LogicException('The cart_lines pipeline did not set the unit price of cart line ' . $cartLine->id . '.');
            assert(is_int($unitPrice->value));

            $subTotal = Money::of($unitPrice->value, $currency)
                ->proportion($cartLine->quantity, $unitPrice->unitQty)
                ->minor;

            $cartLine->subTotal = new Price($subTotal, $currencyModel, 1);
            $cartLine->taxAmount = new Price(0, $currencyModel, 1);
            $cartLine->total = new Price($subTotal, $currencyModel, 1);
            $cartLine->subTotalDiscounted = new Price($subTotal, $currencyModel, 1);
            $cartLine->discountTotal = new Price(0, $currencyModel, 1);
        }

        return $next($cart);
    }
}

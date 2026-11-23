<?php

declare(strict_types=1);

namespace Tests\Feature\Lunar;

use App\Infrastructure\Lunar\Cart\CalculateLines;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Lunar\DataTypes\Price;
use Lunar\Models\Cart;
use Lunar\Models\CartLine;
use Lunar\Models\Currency;
use Lunar\Pipelines\Cart\CalculateLines as LunarCalculateLines;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the project's CalculateLines step against Lunar's own on amounts where the two cannot differ
 * (the unit quantity divides price * quantity), and states where they do.
 */
final class CalculateLinesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The unit prices are set on the lines below; no pricing lookup runs.
        config()->set('lunar.cart.pipelines.cart_lines', []);
    }

    /** @return iterable<string, array{list<array{int, int, int}>}> */
    public static function unambiguousCarts(): iterable
    {
        yield 'one line' => [[[1_999, 3, 1]]];
        yield 'several lines' => [[[1_999, 3, 1], [12_050, 2, 1], [5, 7, 1]]];
        yield 'a price for a pack of 100 bought by the pack' => [[[19_900, 100, 100]]];
        yield 'a price for a pack of 4 bought in multiples of 2' => [[[4_000, 6, 4], [1_000, 8, 4]]];
        yield 'a large amount' => [[[99_999_999, 123, 1]]];
        yield 'nothing' => [[]];
    }

    /** @param  list<array{int, int, int}>  $lines  unit price value, quantity, unit quantity */
    #[DataProvider('unambiguousCarts')]
    public function test_it_agrees_with_lunar_where_the_amount_is_not_ambiguous(array $lines): void
    {
        $lunar = $this->calculate(new LunarCalculateLines(), $this->cart($lines));
        $project = $this->calculate(new CalculateLines(), $this->cart($lines));

        self::assertSame($this->totals($lunar), $this->totals($project));
    }

    public function test_it_rounds_half_away_from_zero_where_lunar_truncates(): void
    {
        // 5 minor units for 2 units, bought once: 2.5 minor units.
        $lines = [[5, 1, 2]];

        self::assertSame([2], $this->totals($this->calculate(new LunarCalculateLines(), $this->cart($lines)))['subTotal']);
        self::assertSame([3], $this->totals($this->calculate(new CalculateLines(), $this->cart($lines)))['subTotal']);
    }

    public function test_it_sets_every_line_total_like_lunar(): void
    {
        $cart = $this->calculate(new CalculateLines(), $this->cart([[1_999, 3, 1]]));
        $line = $cart->lines->first();

        self::assertInstanceOf(CartLine::class, $line);
        self::assertSame(5_997, $line->subTotal?->value);
        self::assertSame(5_997, $line->total?->value);
        self::assertSame(5_997, $line->subTotalDiscounted?->value);
        self::assertSame(0, $line->taxAmount?->value);
        self::assertSame(0, $line->discountTotal?->value);
    }

    public function test_it_fails_when_the_pipeline_left_a_line_without_a_unit_price(): void
    {
        $cart = $this->cart([[100, 1, 1]]);
        $line = $cart->lines->first();
        self::assertInstanceOf(CartLine::class, $line);
        $line->unitPrice = null;

        $this->expectException(LogicException::class);

        $this->calculate(new CalculateLines(), $cart);
    }

    public function test_it_names_a_currency_the_project_does_not_know(): void
    {
        $cart = $this->cart([[100, 1, 1]], 'XTS');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('App\Domain\ValueObjects\Currency');

        $this->calculate(new CalculateLines(), $cart);
    }

    /**
     * @param  list<array{int, int, int}>  $lines
     */
    private function cart(array $lines, string $currencyCode = 'EUR'): Cart
    {
        $currency = new Currency();
        $currency->forceFill(['code' => $currencyCode, 'decimal_places' => 2]);

        $cart = new Cart();
        $cart->setRelation('currency', $currency);

        $models = [];

        foreach ($lines as $index => [$value, $quantity, $unitQuantity]) {
            $line = new CartLine();
            $line->forceFill(['id' => $index + 1, 'quantity' => $quantity]);
            $line->unitPrice = new Price($value, $currency, $unitQuantity);
            $models[] = $line;
        }

        $cart->setRelation('lines', new Collection($models));

        return $cart;
    }

    private function calculate(object $step, Cart $cart): Cart
    {
        $result = $step->handle($cart, static fn (Cart $cart): Cart => $cart);
        self::assertInstanceOf(Cart::class, $result);

        return $result;
    }

    /** @return array{subTotal: list<int|null>, total: list<int|null>, subTotalDiscounted: list<int|null>} */
    private function totals(Cart $cart): array
    {
        $totals = ['subTotal' => [], 'total' => [], 'subTotalDiscounted' => []];

        foreach ($cart->lines as $line) {
            $totals['subTotal'][] = $line->subTotal?->value;
            $totals['total'][] = $line->total?->value;
            $totals['subTotalDiscounted'][] = $line->subTotalDiscounted?->value;
        }

        return $totals;
    }
}

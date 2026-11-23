<?php

declare(strict_types=1);

namespace Tests\Feature\Casts;

use App\Domain\ValueObjects\Currency;
use App\Domain\ValueObjects\Money;
use App\Infrastructure\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class MoneyCastTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('money.default_currency', 'CZK');

        Schema::create('money_cast_rows', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->bigInteger('fee')->nullable();
        });
    }

    public function test_it_stores_the_amount_and_its_currency_in_the_sibling_column(): void
    {
        $row = new MoneyCastRow();
        $row->price = Money::of(123_450, Currency::EUR);
        $row->save();

        $this->assertDatabaseHas('money_cast_rows', ['id' => $row->id, 'price' => 123_450, 'price_currency' => 'EUR']);

        $fresh = MoneyCastRow::query()->findOrFail($row->id);

        self::assertInstanceOf(Money::class, $fresh->price);
        self::assertTrue($fresh->price->equals(Money::of(123_450, Currency::EUR)));
    }

    public function test_a_row_without_a_currency_falls_back_to_the_configured_default(): void
    {
        $id = MoneyCastRow::query()->insertGetId(['price' => 5_000, 'price_currency' => null]);

        self::assertTrue(MoneyCastRow::query()->findOrFail($id)->price?->equals(Money::of(5_000, Currency::CZK)) ?? false);

        config()->set('money.default_currency', 'EUR');

        self::assertTrue(MoneyCastRow::query()->findOrFail($id)->price?->equals(Money::of(5_000, Currency::EUR)) ?? false);
    }

    public function test_null_stays_null(): void
    {
        $row = new MoneyCastRow();
        $row->price = null;
        $row->save();

        self::assertNull(MoneyCastRow::query()->findOrFail($row->id)->price);
    }

    public function test_the_config_mode_stores_only_the_amount(): void
    {
        $row = new MoneyCastRow();
        $row->fee = Money::of(990, Currency::CZK);
        $row->save();

        $this->assertDatabaseHas('money_cast_rows', ['id' => $row->id, 'fee' => 990]);
        self::assertTrue(MoneyCastRow::query()->findOrFail($row->id)->fee?->equals(Money::of(990, Currency::CZK)) ?? false);
    }

    public function test_the_config_mode_refuses_another_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $row = new MoneyCastRow();
        $row->fee = Money::of(990, Currency::EUR);
    }

    public function test_it_refuses_a_value_that_is_not_money(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $row = new MoneyCastRow();
        $row->price = 100;
    }

    public function test_it_refuses_a_column_that_is_not_an_integer(): void
    {
        $id = MoneyCastRow::query()->insertGetId(['price' => 'not a number']);

        $this->expectException(InvalidArgumentException::class);

        MoneyCastRow::query()->findOrFail($id)->price;
    }
}

/**
 * @property Money|null $price
 * @property Money|null $fee
 */
final class MoneyCastRow extends Model
{
    public $timestamps = false;

    protected $table = 'money_cast_rows';

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'fee' => MoneyCast::class . ':' . MoneyCast::CURRENCY_FROM_CONFIG,
        ];
    }
}

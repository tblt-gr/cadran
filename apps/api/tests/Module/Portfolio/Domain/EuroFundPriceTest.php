<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\EuroFundPrice;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EuroFundPriceTest extends TestCase
{
    #[DataProvider('acceptedPrices')]
    public function testItAcceptsAnAbsentPriceOrOne(?string $price): void
    {
        EuroFundPrice::assert(null === $price ? null : DecimalValue::fromString($price));

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{?string}> */
    public static function acceptedPrices(): iterable
    {
        yield 'absent' => [null];
        yield 'one' => ['1'];
        yield 'one with zeros' => ['1.00'];
    }

    #[DataProvider('refusedPrices')]
    public function testItRefusesAnyOtherPrice(string $price): void
    {
        try {
            EuroFundPrice::assert(DecimalValue::fromString($price));
            self::fail('The price must be refused.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame('euro_fund_price_fixed', $exception->problemCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPrices(): iterable
    {
        yield 'a cent above' => ['1.01'];
        yield 'a micro unit below' => ['0.999999999999999999999999'];
        yield 'zero' => ['0'];
        yield 'one and a half' => ['1.5'];
        yield 'negative' => ['-1'];
        yield 'very large' => ['99999999999999999999999999'];
    }
}

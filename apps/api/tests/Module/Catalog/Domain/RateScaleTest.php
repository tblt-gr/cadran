<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * Every rate resolves to a scale, including the single-rate products the
 * catalogue ships today. Resolving the simple case into the general shape is
 * what lets a tiered product arrive later as data, and what stops a screen
 * from guessing whether "1.7 %" covers the whole balance or a slice of it.
 */
final class RateScaleTest extends TestCase
{
    public function testASinglePublishedRateCoversTheWholeBalanceInOneBracket(): void
    {
        $scale = RateScale::wholeBalance(DecimalValue::fromString('1.7'));

        self::assertSame(RateApplication::WHOLE_BALANCE, $scale->application);
        self::assertCount(1, $scale->brackets);
        self::assertFalse($scale->isTiered());
        self::assertSame('0', $scale->brackets[0]->lowerBound->toString());
        self::assertNull($scale->brackets[0]->upperBound);
        self::assertSame('1.7', $scale->brackets[0]->percentage->toString());
    }

    public function testATieredScaleTilesTheAmountsItCovers(): void
    {
        $scale = new RateScale(
            [
                self::bracket('0', '10000', '2'),
                self::bracket('10000', null, '1.5'),
            ],
            RateApplication::WHOLE_BALANCE,
        );

        self::assertTrue($scale->isTiered());
        self::assertSame('10000', $scale->brackets[1]->lowerBound->toString());
    }

    public function testAScaleThatDoesNotStartAtZeroLeavesTheFirstAmountsUncovered(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RateScale([self::bracket('1', null, '1.7')], RateApplication::WHOLE_BALANCE);
    }

    public function testAGapBetweenTwoBracketsIsRefused(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RateScale(
            [self::bracket('0', '10000', '2'), self::bracket('20000', null, '1.5')],
            RateApplication::WHOLE_BALANCE,
        );
    }

    public function testAnOverlapBetweenTwoBracketsIsRefused(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RateScale(
            [self::bracket('0', '10000', '2'), self::bracket('9000', null, '1.5')],
            RateApplication::WHOLE_BALANCE,
        );
    }

    /**
     * A scale that stops at a figure would answer nothing above it, and a
     * consumer reading a larger balance would have to invent a rate.
     */
    public function testTheLastBracketMustRunWithoutALimit(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RateScale([self::bracket('0', '10000', '2')], RateApplication::WHOLE_BALANCE);
    }

    public function testAnEmptyScaleIsRefused(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RateScale([], RateApplication::WHOLE_BALANCE);
    }

    public function testABracketEndingWhereItStartsCoversNothing(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        self::bracket('10000', '10000', '1.5');
    }

    public function testABracketBelowZeroIsRefused(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        self::bracket('-1', null, '1.5');
    }

    /**
     * The scale is read by value, not by literal: a bound written `10000.00`
     * meets a bound written `10000` without leaving a gap.
     */
    public function testBoundsMeetOnTheirValueRatherThanTheirWrittenScale(): void
    {
        $scale = new RateScale(
            [self::bracket('0', '10000.00', '2'), self::bracket('10000', null, '1.5')],
            RateApplication::WHOLE_BALANCE,
        );

        self::assertTrue($scale->isTiered());
    }

    private static function bracket(string $lower, ?string $upper, string $percentage): RateBracket
    {
        return new RateBracket(
            DecimalValue::fromString($lower),
            null === $upper ? null : DecimalValue::fromString($upper),
            DecimalValue::fromString($percentage),
        );
    }
}

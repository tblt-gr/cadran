<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\AppliedRate;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * How a scale reads a balance. The Livret Bleu case is the reason a ceiling
 * is not a hard stop: 22 950 € still earns the regulated rate, and the excess
 * earns the next bracket. Exceeding is allowed; the yield changes.
 *
 * Worked by hand:
 *   22 950 × 1.7 % = 390.15
 *    2 050 × 0.5 % =  10.25
 *   interest       = 400.40
 *   400.40 / 25 000 × 100 = 1.6016 %
 */
final class RateScaleApplicationTest extends TestCase
{
    public function testALivretBleuAboveTheLivretACeilingKeepsPayingTheRegulatedSlice(): void
    {
        $scale = self::livretBleu();

        $applied = $scale->apply(DecimalValue::fromString('25000'));

        self::assertSame(0, $applied->interest?->compareTo(DecimalValue::fromString('400.40')));
        self::assertSame(0, $applied->effectivePercentage?->compareTo(DecimalValue::fromString('1.6016')));
        self::assertSame('0.5', $applied->reachedPercentage->toString());
        self::assertSame('22950', $applied->reachedLowerBound->toString());
        self::assertTrue($applied->rateShiftsAboveFirstBracket);
    }

    public function testABalanceInsideTheFirstBracketDoesNotShiftTheRate(): void
    {
        $applied = self::livretBleu()->apply(DecimalValue::fromString('10000'));

        self::assertSame(0, $applied->interest?->compareTo(DecimalValue::fromString('170')));
        self::assertSame(0, $applied->effectivePercentage?->compareTo(DecimalValue::fromString('1.7')));
        self::assertFalse($applied->rateShiftsAboveFirstBracket);
    }

    public function testAFlatReadingAppliesTheReachedBracketToTheWholeBalance(): void
    {
        $scale = new RateScale(self::livretBleu()->brackets, RateApplication::FLAT_BY_BRACKET);

        $applied = $scale->apply(DecimalValue::fromString('25000'));

        self::assertSame(0, $applied->interest?->compareTo(DecimalValue::fromString('125')));
        self::assertSame(0, $applied->effectivePercentage?->compareTo(DecimalValue::fromString('0.5')));
    }

    public function testAZeroBalanceHasNoEffectiveRate(): void
    {
        $applied = self::livretBleu()->apply(DecimalValue::fromString('0'));

        self::assertNotNull($applied->interest);
        self::assertSame('0', $applied->interest->toString());
        self::assertNull($applied->effectivePercentage);
        self::assertSame(AppliedRate::UNSETTLED_ZERO_BALANCE, $applied->unsettledReason);
    }

    public function testANegativeBalanceIsNotReadAsASavingsScale(): void
    {
        $applied = self::livretBleu()->apply(DecimalValue::fromString('-10'));

        self::assertNull($applied->interest);
        self::assertSame(AppliedRate::UNSETTLED_NEGATIVE_BALANCE, $applied->unsettledReason);
    }

    private static function livretBleu(): RateScale
    {
        return new RateScale(
            [
                new RateBracket(
                    DecimalValue::fromString('0'),
                    DecimalValue::fromString('22950'),
                    DecimalValue::fromString('1.7'),
                ),
                new RateBracket(
                    DecimalValue::fromString('22950'),
                    null,
                    DecimalValue::fromString('0.5'),
                ),
            ],
            RateApplication::MARGINAL,
        );
    }
}

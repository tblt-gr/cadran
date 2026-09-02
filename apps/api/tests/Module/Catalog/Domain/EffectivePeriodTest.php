<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A rule applies over a closed interval, both bounds included. The boundaries
 * are the part worth pinning down: the 1 August revision must apply on
 * 1 August, and the period it replaces must have stopped on 31 July.
 */
final class EffectivePeriodTest extends TestCase
{
    #[DataProvider('coverage')]
    public function testAClosedPeriodCoversBothOfItsBounds(string $day, bool $covered): void
    {
        $period = new EffectivePeriod(CatalogFixture::day('2026-08-01'), CatalogFixture::day('2027-01-31'));

        self::assertSame($covered, $period->covers(CatalogFixture::day($day)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function coverage(): iterable
    {
        yield 'the day before' => ['2026-07-31', false];
        yield 'the first day' => ['2026-08-01', true];
        yield 'a day inside' => ['2026-11-15', true];
        yield 'the last day' => ['2027-01-31', true];
        yield 'the day after' => ['2027-02-01', false];
    }

    public function testAnOpenEndedPeriodCoversEveryLaterDay(): void
    {
        $period = EffectivePeriod::openEndedFrom(CatalogFixture::day('2025-04-25'));

        self::assertFalse($period->covers(CatalogFixture::day('2025-04-24')));
        self::assertTrue($period->covers(CatalogFixture::day('2099-12-31')));
    }

    public function testTwoPeriodsThatShareNoDayDoNotOverlap(): void
    {
        $july = new EffectivePeriod(CatalogFixture::day('2026-02-01'), CatalogFixture::day('2026-07-31'));
        $august = new EffectivePeriod(CatalogFixture::day('2026-08-01'), CatalogFixture::day('2027-01-31'));

        self::assertFalse($july->overlaps($august));
        self::assertFalse($august->overlaps($july));
    }

    public function testTwoPeriodsSharingASingleDayOverlap(): void
    {
        $july = new EffectivePeriod(CatalogFixture::day('2026-02-01'), CatalogFixture::day('2026-08-01'));
        $august = new EffectivePeriod(CatalogFixture::day('2026-08-01'), CatalogFixture::day('2027-01-31'));

        self::assertTrue($july->overlaps($august));
        self::assertTrue($august->overlaps($july));
    }

    public function testAnOpenEndedPeriodOverlapsEverythingThatFollowsIt(): void
    {
        $open = EffectivePeriod::openEndedFrom(CatalogFixture::day('2025-04-25'));
        $later = new EffectivePeriod(CatalogFixture::day('2026-08-01'), CatalogFixture::day('2027-01-31'));
        $earlier = new EffectivePeriod(CatalogFixture::day('2020-01-01'), CatalogFixture::day('2024-12-31'));

        self::assertTrue($open->overlaps($later));
        self::assertTrue($later->overlaps($open));
        self::assertFalse($open->overlaps($earlier));
    }

    public function testAPeriodCannotEndBeforeItStarts(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new EffectivePeriod(CatalogFixture::day('2026-08-01'), CatalogFixture::day('2026-07-31'));
    }
}

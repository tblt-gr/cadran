<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use PHPUnit\Framework\TestCase;

/**
 * A catalogue update appends a period; it never rewrites one. The schedule is
 * what makes that safe: two rules of one kind may not cover the same day, so
 * "the ceiling on 12 March" always has exactly one answer.
 */
final class RuleScheduleTest extends TestCase
{
    public function testTwoRulesOfOneKindCannotCoverTheSameDay(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RuleSchedule([
            CatalogFixture::ceiling('19125.00', '2020-01-01', '2025-04-25'),
            CatalogFixture::ceiling('22950.00', '2025-04-25', null),
        ]);
    }

    public function testAnAppendedPeriodStartingTheDayAfterIsAccepted(): void
    {
        $schedule = new RuleSchedule([
            CatalogFixture::ceiling('19125.00', '2020-01-01', '2025-04-24'),
            CatalogFixture::ceiling('22950.00', '2025-04-25', null),
        ]);

        self::assertCount(2, $schedule->rules);
    }

    public function testAnOpenPeriodBlocksASecondOpenPeriodOfTheSameKind(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new RuleSchedule([
            CatalogFixture::ceiling('19125.00', '2020-01-01', null),
            CatalogFixture::ceiling('22950.00', '2025-04-25', null),
        ]);
    }

    public function testDifferentKindsMayCoverTheSameDays(): void
    {
        $schedule = new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25', null),
            CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
        ]);

        self::assertCount(2, $schedule->rules);
    }

    public function testTheRulesEffectiveOnADateAreTheOnesCoveringIt(): void
    {
        $schedule = new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25', null),
            CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
            CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
        ]);

        $effective = $schedule->effectiveOn(CatalogFixture::day('2026-09-02'));

        self::assertCount(2, $effective);
        self::assertSame([RuleKind::DEPOSIT_CEILING, RuleKind::ANNUAL_RATE], array_map(
            static fn ($rule) => $rule->kind,
            $effective,
        ));
        self::assertSame('1.7', $effective[1]->value->percentage?->toString());
    }

    public function testABusinessDateBeforeAnyRecordedPeriodResolvesToNothing(): void
    {
        $schedule = new RuleSchedule([CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')]);

        self::assertSame([], $schedule->effectiveOn(CatalogFixture::day('2019-06-30')));
    }

    public function testAnEmptyScheduleResolvesToNothing(): void
    {
        self::assertSame([], RuleSchedule::empty()->effectiveOn(CatalogFixture::day('2026-09-02')));
    }
}

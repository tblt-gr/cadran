<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\VerificationState;
use App\Module\Catalog\Domain\YieldKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogEntryTest extends TestCase
{
    #[DataProvider('unguaranteedYields')]
    public function testAProductWithoutAGuaranteedYieldCannotCarryARateRule(YieldKind $yieldKind): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new CatalogEntry(
            CatalogFixture::product($yieldKind),
            new RuleSchedule([CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')]),
        );
    }

    /**
     * @return iterable<string, array{YieldKind}>
     */
    public static function unguaranteedYields(): iterable
    {
        yield 'a PEA or a CTO' => [YieldKind::MARKET];
        yield 'a manually valued asset' => [YieldKind::MANUAL_VALUATION];
        yield 'a product that yields nothing' => [YieldKind::NONE];
    }

    public function testAMarketProductExpectsNoRateAndReportsNoneMissing(): void
    {
        $entry = new CatalogEntry(CatalogFixture::product(YieldKind::MARKET), RuleSchedule::empty());

        $effective = $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-02'));

        self::assertFalse($effective->product->yieldKind->isGuaranteed());
        self::assertSame([], $effective->unavailableRuleKinds);
    }

    public function testARegulatedProductWithNoRateOnThatDayReportsItUnavailable(): void
    {
        $entry = new CatalogEntry(
            CatalogFixture::product(),
            new RuleSchedule([CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')]),
        );

        $effective = $entry->effectiveOn(CatalogFixture::day('2027-02-01'), CatalogFixture::day('2027-02-01'));

        // The rate is unknown for that day, not zero: the next semester has not
        // been sourced yet, and inventing 0 % would understate every estimate.
        self::assertSame([RuleKind::ANNUAL_RATE], $effective->unavailableRuleKinds);
        self::assertSame([], $effective->rules);
    }

    public function testTheBusinessDateSelectsTheRuleAndTodayOnlyGradesIt(): void
    {
        $entry = new CatalogEntry(
            CatalogFixture::product(),
            new RuleSchedule([
                CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ]),
        );

        $effective = $entry->effectiveOn(CatalogFixture::day('2026-03-15'), CatalogFixture::day('2030-01-01'));

        self::assertCount(1, $effective->rules);
        self::assertSame('2.4', $effective->rules[0]->rule->value->percentage?->toString());
        self::assertSame(VerificationState::STALE, $effective->rules[0]->verification);
        self::assertSame('2026-03-15', $effective->asOf->format('Y-m-d'));
    }

    public function testAGuaranteedProductKeepsItsRateRule(): void
    {
        $entry = new CatalogEntry(
            CatalogFixture::product(YieldKind::REGULATED_RATE),
            new RuleSchedule([CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')]),
        );

        $effective = $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-02'));

        self::assertTrue($effective->product->yieldKind->isGuaranteed());
        self::assertSame(VerificationState::VERIFIED, $effective->rules[0]->verification);
    }
}

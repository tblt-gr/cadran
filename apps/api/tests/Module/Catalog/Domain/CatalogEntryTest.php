<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\FinancialProduct;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\VerificationState;
use App\Module\Catalog\Domain\WrapperKind;
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
        $entry = new CatalogEntry(CatalogFixture::marketProduct(), RuleSchedule::empty());

        $effective = $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-02'));

        self::assertFalse($effective->product->yieldKind->isGuaranteed());
        self::assertSame([], $effective->unavailableRuleKinds);
    }

    public function testARuleCannotActivateAnUndeclaredCapability(): void
    {
        $product = new FinancialProduct(
            code: ProductCode::fromString('GENERIC_SAVINGS'),
            displayName: 'Generic savings account',
            jurisdiction: null,
            accountKind: AccountKind::SAVINGS,
            wrapperKind: WrapperKind::NONE,
            yieldKind: YieldKind::CONTRACTUAL_FIXED,
            defaultGroupCode: 'LIQUIDITY_SAVINGS',
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
            ),
            catalogVersion: 1,
        );

        $this->expectException(InvalidCatalogEntry::class);
        $this->expectExceptionMessage('ANNUAL_RATE rules require SUPPORTS_INTEREST.');

        new CatalogEntry(
            $product,
            new RuleSchedule([CatalogFixture::rate('2.5', '2026-01-01')]),
        );
    }

    public function testARegulatedEnvelopeReportsItsCeilingAndRateUnavailable(): void
    {
        $entry = new CatalogEntry(CatalogFixture::product(), RuleSchedule::empty());

        $effective = $entry->effectiveOn(CatalogFixture::day('2026-08-21'), CatalogFixture::day('2026-09-02'));

        self::assertSame([
            RuleKind::DEPOSIT_CEILING,
            RuleKind::ANNUAL_RATE,
        ], $effective->unavailableRuleKinds);
    }

    public function testATaxWrapperReportsBothContributionCeilingsUnavailable(): void
    {
        $product = new FinancialProduct(
            code: ProductCode::fromString('FR_PEA'),
            displayName: 'Plan d’épargne en actions',
            jurisdiction: 'FR',
            accountKind: AccountKind::PORTFOLIO,
            wrapperKind: WrapperKind::TAX_WRAPPER,
            yieldKind: YieldKind::MARKET,
            defaultGroupCode: 'INVESTMENTS_MARKET',
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_HOLDINGS,
                ProductCapability::SUPPORTS_TRADES,
                ProductCapability::SUPPORTS_CONTRIBUTIONS,
                ProductCapability::SUPPORTS_FEES,
                ProductCapability::SUPPORTS_TAX_TRACKING,
            ),
            catalogVersion: 1,
        );
        $entry = new CatalogEntry($product, RuleSchedule::empty());

        $effective = $entry->effectiveOn(CatalogFixture::day('2026-08-21'), CatalogFixture::day('2026-09-02'));

        self::assertSame([
            RuleKind::CONTRIBUTION_CEILING,
            RuleKind::COMBINED_CONTRIBUTION_CEILING,
        ], $effective->unavailableRuleKinds);
    }

    public function testARegulatedProductWithNoRateOnThatDayReportsItUnavailable(): void
    {
        $entry = new CatalogEntry(
            CatalogFixture::product(),
            new RuleSchedule([
                CatalogFixture::ceiling('22950', '2025-04-25'),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ]),
        );

        $effective = $entry->effectiveOn(CatalogFixture::day('2027-02-01'), CatalogFixture::day('2027-02-01'));

        // The rate is unknown for that day, not zero: the next semester has not
        // been sourced yet, and inventing 0 % would understate every estimate.
        self::assertSame([RuleKind::ANNUAL_RATE], $effective->unavailableRuleKinds);
        self::assertCount(1, $effective->rules);
        self::assertSame(RuleKind::DEPOSIT_CEILING, $effective->rules[0]->rule->kind);
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

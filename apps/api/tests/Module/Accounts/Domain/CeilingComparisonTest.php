<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\CeilingCheckStatus;
use App\Module\Accounts\Domain\CeilingUnsettledReason;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * A ceiling is compared, never enforced. A Livret Bleu (or credited interest
 * on a Livret A) may sit above 22 950 €: the verdict is a warning, not a
 * refusal, and the scale — not the ceiling — decides what the excess earns.
 */
final class CeilingComparisonTest extends TestCase
{
    public function testABalanceAtTheCeilingStaysWithinIt(): void
    {
        $check = $this->livretACeiling()->compare(self::euros('22950'));

        self::assertSame(CeilingCheckStatus::WITHIN, $check->status);
        self::assertFalse($check->isWarning());
        self::assertSame('22950', $check->measured?->value->toString());
    }

    public function testABalanceAboveTheCeilingIsAWarningNeverARefusal(): void
    {
        $check = $this->livretACeiling()->compare(self::euros('25000'));

        self::assertSame(CeilingCheckStatus::EXCEEDED, $check->status);
        self::assertTrue($check->isWarning());
        self::assertFalse($check->isRefusal());
        self::assertSame(0, $check->excess?->value->compareTo(DecimalValue::fromString('2050')));
    }

    public function testAMissingBalanceLeavesTheCheckUnsettledNotZero(): void
    {
        $check = $this->livretACeiling()->compare(null);

        self::assertSame(CeilingCheckStatus::UNSETTLED, $check->status);
        self::assertSame(CeilingUnsettledReason::MISSING_VALUATION, $check->unsettledReason);
        self::assertNull($check->measured);
    }

    public function testAContributionCeilingCannotBeSettledFromABalance(): void
    {
        $entry = new CatalogEntry(CatalogFixture::taxWrapperProduct(), new RuleSchedule([
            CatalogFixture::contributionCeiling('150000.00', '2014-01-01'),
        ]));
        $rules = \App\Module\Accounts\Domain\AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_PEA', kind: \App\Module\Catalog\Domain\AccountKind::PORTFOLIO),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );

        $check = $rules->ceilings[0]->effective()->compare(self::euros('180000'));

        self::assertSame(CeilingCheckStatus::UNSETTLED, $check->status);
        self::assertSame(CeilingUnsettledReason::CONTRIBUTIONS_NOT_TRACKED, $check->unsettledReason);
    }

    public function testACombinedCeilingCannotBeSettledFromOneAccount(): void
    {
        $entry = new CatalogEntry(CatalogFixture::taxWrapperProduct(), new RuleSchedule([
            CatalogFixture::combinedContributionCeiling('225000.00', '2019-05-24'),
        ]));
        $rules = \App\Module\Accounts\Domain\AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_PEA', kind: \App\Module\Catalog\Domain\AccountKind::PORTFOLIO),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );

        $check = $rules->ceilings[0]->effective()->compare(self::euros('100000'));

        self::assertSame(CeilingCheckStatus::UNSETTLED, $check->status);
        self::assertSame(CeilingUnsettledReason::COMBINED_CEILING, $check->unsettledReason);
    }

    public function testACeilingInAnotherUnitIsNotComparable(): void
    {
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
        ]));
        $rules = \App\Module\Accounts\Domain\AccountRules::fromProduct(
            AccountFixture::account(assetCode: 'USD'),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );

        $check = $rules->ceilings[0]->effective()->compare(
            new AssetAmount(DecimalValue::fromString('25000'), AssetCode::fromString('USD')),
        );

        self::assertSame(CeilingCheckStatus::NOT_COMPARABLE, $check->status);
    }

    private function livretACeiling(): \App\Module\Accounts\Domain\AccountCeiling
    {
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
        ]));
        $rules = \App\Module\Accounts\Domain\AccountRules::fromProduct(
            AccountFixture::account(),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );

        return $rules->ceilings[0]->effective();
    }

    private static function euros(string $value): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString('EUR'));
    }
}

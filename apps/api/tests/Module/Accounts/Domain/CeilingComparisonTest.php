<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\CeilingCheckStatus;
use App\Module\Accounts\Domain\CeilingUnsettledReason;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * A ceiling is compared, never enforced. A Livret Bleu (or credited interest
 * on a Livret A) may sit above 22 950 €: the verdict is a warning, not a
 * refusal, and the scale, not the ceiling, decides what the excess earns.
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

    public function testAContributionCeilingIsUnsettledUntilContributionsAreKnown(): void
    {
        $entry = new CatalogEntry(CatalogFixture::taxWrapperProduct(), new RuleSchedule([
            CatalogFixture::contributionCeiling('150000.00', '2014-01-01'),
        ]));
        $rules = \App\Module\Accounts\Domain\AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_PEA', kind: \App\Module\Catalog\Domain\AccountKind::PORTFOLIO),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );

        $check = $rules->ceilings[0]->effective()->compare(null);

        self::assertSame(CeilingCheckStatus::UNSETTLED, $check->status);
        self::assertSame(CeilingUnsettledReason::CONTRIBUTIONS_NOT_TRACKED, $check->unsettledReason);
    }

    public function testAContributionCeilingIsComparedToWhatWasPaidIn(): void
    {
        $entry = new CatalogEntry(CatalogFixture::taxWrapperProduct(), new RuleSchedule([
            CatalogFixture::contributionCeiling('150000.00', '2014-01-01'),
        ]));
        $rules = \App\Module\Accounts\Domain\AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_PEA', kind: \App\Module\Catalog\Domain\AccountKind::PORTFOLIO),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );

        self::assertSame(CeilingCheckStatus::WITHIN, $rules->ceilings[0]->effective()->compare(self::euros('6200'))->status);
        self::assertSame(CeilingCheckStatus::EXCEEDED, $rules->ceilings[0]->effective()->compare(self::euros('180000'))->status);
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

    public function testTheRuleComparesTheInterestFreeBasisToTheBalanceNotToContributions(): void
    {
        $rule = $this->rules(CatalogFixture::product(), CatalogFixture::ceiling('22950.00', '2025-04-25'), CatalogFixture::rate('1.7', '2026-08-01'))->ceilings[0];

        $check = $rule->compareTo(self::euros('23950.00'), self::euros('1.00'));

        self::assertSame(CeilingBasis::BALANCE_EXCLUDING_INTEREST, $rule->basis);
        self::assertSame(CeilingCheckStatus::EXCEEDED, $check->status);
        self::assertSame('23950.00', $check->measured?->value->toString());
        self::assertSame('1000.00', $check->excess?->value->toString());
        self::assertSame(CeilingUnsettledReason::MISSING_VALUATION, $rule->compareTo(null, self::euros('1.00'))->unsettledReason);
    }

    public function testTheRuleComparesAContributionCeilingToContributionsNotToTheBalance(): void
    {
        $rule = $this->rules(CatalogFixture::taxWrapperProduct(), CatalogFixture::contributionCeiling('150000.00', '2014-01-01'))->ceilings[0];

        self::assertSame(CeilingCheckStatus::WITHIN, $rule->compareTo(self::euros('180000'), self::euros('6200'))->status);
        self::assertSame(CeilingUnsettledReason::CONTRIBUTIONS_NOT_TRACKED, $rule->compareTo(self::euros('180000'), null)->unsettledReason);
    }

    public function testARateAppliesToTheBalanceAndStaysNullWithoutOne(): void
    {
        $rule = $this->rules(CatalogFixture::product(), CatalogFixture::rate('1.7', '2026-08-01'))->rates[0];

        self::assertNull($rule->applyTo(null));
        self::assertSame(0, $rule->applyTo(self::euros('1000.00'))?->interest?->compareTo(DecimalValue::fromString('17')));
    }

    private function rules(\App\Module\Catalog\Domain\FinancialProduct $product, \App\Module\Catalog\Domain\ProductRule ...$schedule): \App\Module\Accounts\Domain\AccountRules
    {
        $pea = \App\Module\Catalog\Domain\AccountKind::PORTFOLIO === $product->accountKind;

        return \App\Module\Accounts\Domain\AccountRules::fromProduct(
            $pea ? AccountFixture::account(productCode: 'FR_PEA', kind: $product->accountKind) : AccountFixture::account(),
            (new CatalogEntry($product, new RuleSchedule(array_values($schedule))))->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day('2026-09-03')),
            \App\Module\Accounts\Domain\AccountRuleOverrides::none(),
        );
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

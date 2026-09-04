<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModelIsArchived;
use App\Module\Accounts\Domain\ProductModelOrigin;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Catalog\Domain\ProductNature;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use PHPUnit\Framework\TestCase;

/**
 * A workspace model describes a product nobody published. The invariants that
 * matter are therefore the ones that keep it honest: it cannot promise a
 * return its yield does not owe, it cannot activate a rule its capabilities do
 * not support, and archiving it never removes what it already said.
 */
final class ProductModelTest extends TestCase
{
    public function testAModelStatesTheSideOfTheBalanceSheetItSitsOn(): void
    {
        self::assertSame(ProductNature::ASSET, ProductModelFixture::model()->nature());

        $loan = ProductModelFixture::model(
            family: AccountKind::LIABILITY,
            yieldKind: YieldKind::NONE,
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_LIABILITY,
            ),
        );

        self::assertSame(ProductNature::LIABILITY, $loan->nature());
    }

    public function testAModelValuedByTheMarketCannotCarryARatePeriod(): void
    {
        $this->expectException(InvalidProductModel::class);

        ProductModelFixture::model(
            schedule: new ModelRuleSchedule([ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01')]),
            yieldKind: YieldKind::MARKET,
        );
    }

    public function testARulePeriodRequiresTheCapabilityItConsumes(): void
    {
        $this->expectException(InvalidProductModel::class);

        ProductModelFixture::model(
            schedule: new ModelRuleSchedule([ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01')]),
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
            ),
        );
    }

    public function testAValuationModeRequiresTheCapabilityItConsumes(): void
    {
        $this->expectException(InvalidProductModel::class);

        // A portfolio family may be valued by its positions, but only once the
        // model declares it holds them.
        ProductModelFixture::model(
            family: AccountKind::PORTFOLIO,
            yieldKind: YieldKind::MARKET,
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
            ),
            valuationMode: AccountValuationMode::PORTFOLIO,
        );
    }

    public function testAPortfolioValuationIsRefusedOnAFamilyThatHoldsNoPosition(): void
    {
        $this->expectException(InvalidProductModel::class);

        ProductModelFixture::model(valuationMode: AccountValuationMode::PORTFOLIO);
    }

    public function testALiabilityFamilyRequiresTheLiabilityCapability(): void
    {
        $this->expectException(InvalidProductModel::class);

        ProductModelFixture::model(family: AccountKind::LIABILITY, yieldKind: YieldKind::NONE);
    }

    public function testARecordedPeriodIsAppendedAndClosesTheOpenEndedOneItSupersedes(): void
    {
        $model = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]));

        $revised = $model->withRule(
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2027-07-01'),
            new \DateTimeImmutable('2026-09-05T09:00:00+00:00'),
        );

        self::assertCount(2, $revised->schedule->rules);
        self::assertSame('2027-06-30', $revised->schedule->rules[0]->period->validTo?->format('Y-m-d'));
        self::assertNull($revised->schedule->rules[1]->period->validTo);
        self::assertSame(2, $revised->version);
        // The model this one was derived from keeps saying what it said.
        self::assertNull($model->schedule->rules[0]->period->validTo);
    }

    public function testAnOpenEndedPeriodStaysInForceWithNoKnownEnd(): void
    {
        $model = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]));

        $effective = $model->schedule->effectiveOn(ProductModelFixture::day('2099-12-31'));

        self::assertCount(1, $effective);
        self::assertNull($effective[0]->period->validTo);
    }

    public function testADuplicateCopiesTheDescriptionAndTheDatesUnderAFreshIdentity(): void
    {
        $model = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01', '2026-06-30'),
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b2', '30000', '2026-01-01'),
        ]));

        $copy = $model->duplicateAs(
            '00000000-0000-7000-8000-0000000000e9',
            'Livret Banque X (copie)',
            ['00000000-0000-7000-8000-0000000000c1', '00000000-0000-7000-8000-0000000000c2'],
            new \DateTimeImmutable('2026-09-05T09:00:00+00:00'),
        );

        self::assertSame('00000000-0000-7000-8000-0000000000e9', $copy->id);
        self::assertSame(ProductModelOrigin::WORKSPACE_MODEL, $copy->provenance->origin);
        self::assertSame($model->id, $copy->provenance->sourceModelId);
        self::assertSame(1, $copy->version);
        self::assertCount(2, $copy->schedule->rules);
        // The effective dates are the source's, unchanged: a copy of a rate
        // that ran last spring still says it ran last spring. Periods are
        // ordered by rule kind, so the ceiling comes before the rate.
        $rate = $copy->schedule->rules[1];
        self::assertSame(RuleKind::ANNUAL_RATE, $rate->kind);
        self::assertSame('2026-01-01', $rate->period->validFrom->format('Y-m-d'));
        self::assertSame('2026-06-30', $rate->period->validTo?->format('Y-m-d'));
        self::assertNotSame($model->schedule->rules[1]->id, $rate->id);
    }

    public function testAnArchivedModelKeepsItsPeriodsAndRefusesNewOnes(): void
    {
        $model = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]));

        $archived = $model->archive(new \DateTimeImmutable('2026-09-05T09:00:00+00:00'));

        self::assertTrue($archived->isArchived());
        self::assertCount(1, $archived->schedule->rules);

        $this->expectException(ProductModelIsArchived::class);
        $archived->withRule(
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-10-01'),
            new \DateTimeImmutable('2026-10-01T09:00:00+00:00'),
        );
    }

    public function testAnArchivedModelIsArchivedOnlyOnce(): void
    {
        $archived = ProductModelFixture::model()->archive(new \DateTimeImmutable('2026-09-05T09:00:00+00:00'));

        $this->expectException(ProductModelIsArchived::class);
        $archived->archive(new \DateTimeImmutable('2026-09-06T09:00:00+00:00'));
    }

    public function testAModelRefusesABlankName(): void
    {
        $this->expectException(InvalidProductModel::class);

        ProductModelFixture::model(name: '  ');
    }

    public function testACeilingPeriodRequiresTheBalanceCapability(): void
    {
        $this->expectException(InvalidProductModel::class);

        ProductModelFixture::model(
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '30000', '2026-01-01'),
            ]),
            yieldKind: YieldKind::NONE,
            capabilities: ProductCapabilities::of(ProductCapability::SUPPORTS_TRANSACTIONS),
        );
    }

    public function testARegulatedEnvelopeReportsItsCeilingAndRateUnavailable(): void
    {
        $model = ProductModelFixture::model(wrapperKind: WrapperKind::REGULATED_SAVINGS);

        $effective = $model->effectiveOn(ProductModelFixture::day('2026-09-02'));

        self::assertSame([RuleKind::DEPOSIT_CEILING, RuleKind::ANNUAL_RATE], $effective->unavailableRuleKinds);
    }

    public function testABusinessDateNoPeriodCoversLeavesTheRuleUnavailable(): void
    {
        $model = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01', '2026-06-30'),
        ]));

        $effective = $model->effectiveOn(ProductModelFixture::day('2026-07-15'));

        self::assertSame([], $effective->rules);
        self::assertSame([RuleKind::ANNUAL_RATE], $effective->unavailableRuleKinds);
    }

    public function testAMarketModelExpectsNoRateAndReportsNoneMissing(): void
    {
        $model = ProductModelFixture::model(
            yieldKind: YieldKind::MARKET,
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_HOLDINGS,
            ),
        );

        $effective = $model->effectiveOn(ProductModelFixture::day('2026-09-02'));

        self::assertFalse($model->yieldKind->isGuaranteed());
        self::assertSame([], $effective->unavailableRuleKinds);
    }

    public function testTheCeilingBasisFollowsTheRecordedCeilingKinds(): void
    {
        $withBalanceCeiling = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '30000', '2026-01-01'),
        ]));
        // A NONE envelope would otherwise publish NONE while a balance ceiling
        // is sitting on the model: the recorded kind is the measure.
        self::assertSame(CeilingBasis::TOTAL_BALANCE, $withBalanceCeiling->ceilingBasis());

        $fromEnvelope = ProductModelFixture::model(wrapperKind: WrapperKind::REGULATED_SAVINGS);
        self::assertSame(CeilingBasis::BALANCE_EXCLUDING_INTEREST, $fromEnvelope->ceilingBasis());

        self::assertSame(CeilingBasis::NONE, ProductModelFixture::model()->ceilingBasis());

        $mixed = ProductModelFixture::model(
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '30000', '2026-01-01'),
                ProductModelFixture::ceiling(
                    '00000000-0000-7000-8000-0000000000b2',
                    '150000',
                    '2026-01-01',
                    kind: RuleKind::CONTRIBUTION_CEILING,
                ),
            ]),
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
                ProductCapability::SUPPORTS_CONTRIBUTIONS,
            ),
        );
        self::assertSame(CeilingBasis::NONE, $mixed->ceilingBasis());
    }
}

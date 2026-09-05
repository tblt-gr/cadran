<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountRuleLayer;
use App\Module\Accounts\Domain\AccountRuleOverrides;
use App\Module\Accounts\Domain\AccountRules;
use App\Module\Accounts\Domain\AccountRulesOrigin;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\VerificationState;
use App\Module\Catalog\Domain\WrapperKind;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * What an account answers when asked "which rules applied to you on this day".
 *
 * The account stores none of this, so every assertion here is really about the
 * resolution: the right period, the right measure, and a gap that stays a gap.
 */
final class AccountRulesTest extends TestCase
{
    private const string TODAY = '2026-09-03';

    public function testTheCeilingAndRateOfTheDayAreTheOnesWhosePeriodCoversIt(): void
    {
        $rules = $this->resolve(
            [
                CatalogFixture::ceiling('22950.00', '2025-04-25'),
                CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ],
            asOf: '2026-09-02',
        );

        self::assertSame(AccountRulesOrigin::SYSTEM_CATALOG, $rules->origin);
        self::assertSame('FR_LIVRET_A', $rules->productCode?->toString());
        self::assertCount(1, $rules->ceilings);
        self::assertSame('22950.00', $rules->ceilings[0]->effective()->amount->value->toString());
        self::assertCount(1, $rules->rates);
        self::assertSame('1.7', $rules->rates[0]->effective()->scale->brackets[0]->percentage->toString());
    }

    /**
     * The revision that superseded a rate does not rewrite it. Reading the
     * earlier date must still answer with the earlier figure, or a past
     * statement could never be checked again.
     */
    public function testAnEarlierBusinessDateAnswersWithTheRateOfThatSemester(): void
    {
        $rules = $this->resolve(
            [
                CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ],
            asOf: '2026-03-15',
        );

        self::assertSame('2.4', $rules->rates[0]->effective()->scale->brackets[0]->percentage->toString());
    }

    /**
     * An open end means "in force until a revision closes it". Reading it as
     * an expiry would make a ceiling disappear the day after it was recorded.
     */
    public function testAnOpenEndedPeriodStaysInForceForEveryLaterDate(): void
    {
        $rules = $this->resolve([CatalogFixture::ceiling('22950.00', '2025-04-25')], asOf: '2099-12-31');

        self::assertCount(1, $rules->ceilings);
        self::assertNull($rules->ceilings[0]->effective()->period->validTo);
        self::assertSame('22950.00', $rules->ceilings[0]->effective()->amount->value->toString());
        // The dated rate beside it did expire, and says so rather than
        // silently keeping its last value.
        self::assertSame([RuleKind::ANNUAL_RATE], $rules->unavailableRuleKinds);
    }

    public function testADateNoPeriodCoversLeavesTheRuleUnavailableRatherThanZero(): void
    {
        $rules = $this->resolve(
            [
                CatalogFixture::ceiling('22950.00', '2025-04-25'),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ],
            asOf: '2027-06-30',
        );

        self::assertCount(1, $rules->ceilings);
        self::assertSame([], $rules->rates);
        self::assertSame([RuleKind::ANNUAL_RATE], $rules->unavailableRuleKinds);
    }

    public function testADepositCeilingIsMeasuredOnWhatWasDepositedNotOnTheTotalBalance(): void
    {
        $ceiling = $this->resolve([CatalogFixture::ceiling('22950.00', '2025-04-25')])->ceilings[0];

        self::assertSame(CeilingBasis::BALANCE_EXCLUDING_INTEREST, $ceiling->basis);
        self::assertFalse($ceiling->basis->countsCreditedInterest());
        self::assertFalse($ceiling->basis->spansSeveralAccounts());
    }

    public function testAPlanSeparatesItsOwnContributionCeilingFromTheOneItShares(): void
    {
        $entry = new CatalogEntry(CatalogFixture::taxWrapperProduct(), new RuleSchedule([
            CatalogFixture::contributionCeiling('150000.00', '2014-01-01'),
            CatalogFixture::combinedContributionCeiling('225000.00', '2019-05-24'),
        ]));

        $rules = AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_PEA', kind: AccountKind::PORTFOLIO),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        self::assertSame(
            [CeilingBasis::CONTRIBUTIONS, CeilingBasis::COMBINED_CONTRIBUTIONS],
            array_map(static fn ($ceiling) => $ceiling->basis, $rules->ceilings),
        );
        self::assertFalse($rules->ceilings[0]->basis->spansSeveralAccounts());
        // Reading this plan alone can never settle the shared allowance: the
        // sibling plan holds the rest of it.
        self::assertTrue($rules->ceilings[1]->basis->spansSeveralAccounts());
    }

    /**
     * A ceiling published in euros says nothing about an account denominated
     * in another unit, and no conversion rate ships with the application.
     */
    public function testACeilingInAnotherUnitThanTheAccountIsNotComparable(): void
    {
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
        ]));

        $euros = AccountRules::fromProduct(
            AccountFixture::account(),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );
        $dollars = AccountRules::fromProduct(
            AccountFixture::account(assetCode: 'USD'),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        self::assertTrue($euros->ceilings[0]->effective()->isMeasurable());
        self::assertFalse($dollars->ceilings[0]->effective()->isMeasurable());
    }

    public function testARegulatedRateIsOwedToTheHolderAndResolvesToOneBracket(): void
    {
        $rate = $this->resolve([CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')])->rates[0];

        self::assertTrue($rate->effective()->guaranteed);
        self::assertSame(RateApplication::MARGINAL, $rate->effective()->scale->application);
        self::assertCount(1, $rate->effective()->scale->brackets);
        self::assertSame('0', $rate->effective()->scale->brackets[0]->lowerBound->toString());
        self::assertNull($rate->effective()->scale->brackets[0]->upperBound);
    }

    /**
     * A minimum rate is the floor the insurer committed to, so it stays owed
     * to the holder even on a contract whose published rate is revised every
     * year. Reading the guarantee from the product's yield alone would present
     * that floor as merely the rate published today.
     */
    public function testAMinimumRateStaysOwedToTheHolderOnARevisableContract(): void
    {
        $entry = new CatalogEntry(CatalogFixture::lifeInsuranceProduct(), new RuleSchedule([
            CatalogFixture::rate('2.5', '2026-01-01', '2026-12-31'),
            CatalogFixture::minRate('0.8', '2026-01-01', '2026-12-31'),
        ]));

        $rules = AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_LIFE_INSURANCE', kind: AccountKind::INSURANCE_CONTRACT),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        $guaranteed = [];
        foreach ($rules->rates as $rate) {
            $guaranteed[$rate->kind->value] = $rate->effective()->guaranteed;
        }

        self::assertSame(['ANNUAL_RATE' => false, 'MIN_RATE' => true], $guaranteed);
    }

    public function testARuleThatIsNeitherACeilingNorARateTravelsAsATerm(): void
    {
        $entry = new CatalogEntry(CatalogFixture::marketProduct(), new RuleSchedule([
            CatalogFixture::eligibility('NO_REGULATORY_CONTRIBUTION_CEILING', '2026-08-22'),
        ]));

        $rules = AccountRules::fromProduct(
            AccountFixture::account(productCode: 'FR_CTO', kind: AccountKind::PORTFOLIO),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        self::assertSame([], $rules->ceilings);
        self::assertSame([], $rules->rates);
        self::assertCount(1, $rules->terms);
        self::assertSame(RuleKind::ELIGIBILITY, $rules->terms[0]->kind);
        self::assertSame('NO_REGULATORY_CONTRIBUTION_CEILING', $rules->terms[0]->effective()->token);
    }

    /**
     * Staleness is about our own review cycle, not about the period read: an
     * old rate read against an old date is still the right figure.
     */
    public function testVerificationIsGradedAgainstTodayAndNotAgainstTheBusinessDate(): void
    {
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25', null, verifiedOn: '2025-01-01'),
        ]));

        $rules = AccountRules::fromProduct(
            AccountFixture::account(),
            $entry->effectiveOn(CatalogFixture::day('2025-05-01'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        self::assertSame(VerificationState::STALE, $rules->ceilings[0]->effective()->verification);
    }

    public function testAnAccountDescribedByHandInheritsNoRuleAndReportsNoGap(): void
    {
        $rules = AccountRules::withoutProduct(
            AccountFixture::account(productCode: null),
            CatalogFixture::day('2026-09-02'),
            AccountRuleOverrides::none(),
        );

        self::assertSame(AccountRulesOrigin::NO_PRODUCT, $rules->origin);
        self::assertNull($rules->productCode);
        self::assertSame([], $rules->ceilings);
        self::assertSame([], $rules->rates);
        self::assertSame([], $rules->terms);
        self::assertSame([], $rules->unavailableRuleKinds);
    }

    /**
     * A withdrawn product and an account that never had one are different
     * answers. Neither of them means "this account has no ceiling".
     */
    public function testAWithdrawnProductKeepsItsReferenceAndResolvesNothing(): void
    {
        $rules = AccountRules::withWithdrawnProduct(
            AccountFixture::account(),
            CatalogFixture::day('2026-09-02'),
            AccountRuleOverrides::none(),
        );

        self::assertSame(AccountRulesOrigin::PRODUCT_WITHDRAWN, $rules->origin);
        self::assertSame('FR_LIVRET_A', $rules->productCode?->toString());
        self::assertSame([], $rules->ceilings);
    }

    public function testAModelBackedAccountResolvesItsRulesWithNoPublicationOrVerification(): void
    {
        $model = ProductModelFixture::model(
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '22950.00', '2025-04-25'),
                ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-08-01'),
            ]),
        );

        $rules = AccountRules::fromModel(
            AccountFixture::account(productCode: null, productModelId: $model->id),
            $model->effectiveOn(ProductModelFixture::day('2026-09-02')),
            null,
            AccountRuleOverrides::none(),
        );

        self::assertSame(AccountRulesOrigin::WORKSPACE_MODEL, $rules->origin);
        self::assertSame($model->id, $rules->productModelId);
        self::assertNull($rules->productCode);
        self::assertCount(1, $rules->ceilings);
        self::assertNull($rules->ceilings[0]->effective()->verification);
        self::assertNull($rules->ceilings[0]->effective()->source);
        self::assertCount(1, $rules->rates);
        self::assertNull($rules->rates[0]->effective()->verification);
        self::assertNull($rules->rates[0]->effective()->source);
        self::assertTrue($rules->rates[0]->effective()->guaranteed);
    }

    /**
     * A REGULATED_SAVINGS envelope expects a deposit ceiling. Recording none
     * must show it as unavailable, not silently absent.
     */
    public function testAModelReportsTheCeilingItsEnvelopeExpectsAndDoesNotCarry(): void
    {
        $model = ProductModelFixture::model(wrapperKind: WrapperKind::REGULATED_SAVINGS);

        $rules = AccountRules::fromModel(
            AccountFixture::account(productCode: null, productModelId: $model->id),
            $model->effectiveOn(ProductModelFixture::day('2026-09-02')),
            null,
            AccountRuleOverrides::none(),
        );

        self::assertSame([], $rules->ceilings);
        // The model's default yield (CONTRACTUAL_FIXED) also expects a rate,
        // recorded here too so neither gap is mistaken for the other.
        self::assertSame([RuleKind::DEPOSIT_CEILING, RuleKind::ANNUAL_RATE], $rules->unavailableRuleKinds);
    }

    /**
     * The whole point of an override: the catalogue keeps saying what it says,
     * and the account answers with the local claim while both stay readable.
     */
    public function testAnOverrideWinsOverTheCatalogueWithoutHidingIt(): void
    {
        $rules = $this->resolve(
            [CatalogFixture::ceiling('22950.00', '2025-04-25')],
            asOf: '2026-09-02',
            overrides: new AccountRuleOverrides([
                AccountRuleOverrideFixture::ceiling('00000000-0000-7000-8000-0000000000c1', '30000.00', '2026-01-01'),
            ]),
        );

        $ceiling = $rules->ceilings[0];
        self::assertSame(AccountRuleLayer::OVERRIDE, $ceiling->effectiveLayer);
        self::assertSame('30000.00', $ceiling->effective()->amount->value->toString());

        $catalog = $ceiling->catalog;
        self::assertNotNull($catalog);
        // The published figure is still there, still sourced, still verifiable.
        self::assertSame('22950.00', $catalog->amount->value->toString());
        self::assertNotNull($catalog->source);

        // And the local one is recognisable as local: no publication, and the
        // claim that explains it.
        $override = $ceiling->override;
        self::assertNotNull($override);
        self::assertNull($override->source);
        self::assertNull($override->verification);
        self::assertNotNull($override->override);
        self::assertSame(
            'Negotiated with the branch when the contract was signed.',
            $override->override->reason,
        );
    }

    /**
     * An override is dated like everything else. Outside its period the
     * account resolves against what it inherits, so a promotional rate that
     * ran for one semester stops applying on its own.
     */
    public function testAnOverrideOnlyWinsOnTheDatesItCovers(): void
    {
        $overrides = new AccountRuleOverrides([
            AccountRuleOverrideFixture::ceiling(
                '00000000-0000-7000-8000-0000000000c1',
                '30000.00',
                '2026-01-01',
                '2026-06-30',
            ),
        ]);
        $catalogue = [CatalogFixture::ceiling('22950.00', '2025-04-25')];

        $inside = $this->resolve($catalogue, asOf: '2026-03-15', overrides: $overrides);
        $after = $this->resolve($catalogue, asOf: '2026-09-02', overrides: $overrides);

        self::assertSame('30000.00', $inside->ceilings[0]->effective()->amount->value->toString());
        self::assertSame(AccountRuleLayer::CATALOG, $after->ceilings[0]->effectiveLayer);
        self::assertSame('22950.00', $after->ceilings[0]->effective()->amount->value->toString());
        self::assertNull($after->ceilings[0]->override);
    }

    /**
     * Withdrawing is not ending. It removes the claim from every date, past
     * ones included, and each of them falls back on the inherited rule that
     * covered it — the dated one, not whatever is in force today.
     */
    public function testAWithdrawnOverrideStopsAnsweringOnPastDatesToo(): void
    {
        $rules = $this->resolve(
            [
                CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
                CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
            ],
            asOf: '2026-03-15',
            overrides: new AccountRuleOverrides([
                AccountRuleOverrideFixture::rate(
                    '00000000-0000-7000-8000-0000000000c1',
                    '2026-01-01',
                    withdrawnAt: new \DateTimeImmutable('2026-09-05T09:00:00+00:00'),
                ),
            ]),
        );

        self::assertSame(AccountRuleLayer::CATALOG, $rules->rates[0]->effectiveLayer);
        self::assertSame('2.4', $rules->rates[0]->effective()->scale->brackets[0]->percentage->toString());
        self::assertNull($rules->rates[0]->override);
    }

    /**
     * A gap the catalogue leaves is a gap only while nothing answers for it. A
     * local claim is an answer, so the kind stops being reported as unknown.
     */
    public function testAnOverrideFillsAGapTheCatalogueLeftUnavailable(): void
    {
        $rules = $this->resolve(
            [CatalogFixture::ceiling('22950.00', '2025-04-25')],
            asOf: '2026-09-02',
            overrides: new AccountRuleOverrides([
                AccountRuleOverrideFixture::rate('00000000-0000-7000-8000-0000000000c1', '2026-01-01'),
            ]),
        );

        self::assertSame([], $rules->unavailableRuleKinds);
        self::assertCount(1, $rules->rates);
        self::assertSame(AccountRuleLayer::OVERRIDE, $rules->rates[0]->effectiveLayer);
        self::assertNull($rules->rates[0]->catalog);
    }

    /**
     * A rate the holder typed is graded by the promise of the product behind
     * it, never by its own say-so: it inherits the guarantee of the yield it
     * replaces rather than acquiring one by being written down.
     */
    public function testALocalRateIsGradedByTheYieldOfTheProductItReplaces(): void
    {
        $regulated = $this->resolve(
            [CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')],
            asOf: '2026-09-02',
            overrides: new AccountRuleOverrides([
                AccountRuleOverrideFixture::rate('00000000-0000-7000-8000-0000000000c1', '2026-01-01'),
            ]),
        );

        self::assertTrue($regulated->rates[0]->effective()->guaranteed);
    }

    /**
     * A model copied from a catalogue product stops following it. Both figures
     * are shown so the holder can see that the drift between them is theirs,
     * and the account still resolves against the model.
     */
    public function testAModelCopiedFromTheCatalogueShowsBothFiguresAtOnce(): void
    {
        $model = ProductModelFixture::model(
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling(
                    '00000000-0000-7000-8000-0000000000b1',
                    '25000.00',
                    '2025-04-25',
                    kind: RuleKind::DEPOSIT_CEILING,
                ),
            ]),
        );
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
        ]));

        $rules = AccountRules::fromModel(
            AccountFixture::account(productCode: null, productModelId: $model->id),
            $model->effectiveOn(ProductModelFixture::day('2026-09-02')),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        $ceiling = $rules->ceilings[0];
        self::assertSame(AccountRuleLayer::INHERITED, $ceiling->effectiveLayer);
        self::assertSame('25000.00', $ceiling->effective()->amount->value->toString());
        self::assertSame('22950.00', $ceiling->catalog?->amount->value->toString());
        self::assertNull($ceiling->override);
        // The catalogue is read for comparison only. What the account is
        // expected to carry is decided by the model it follows.
        self::assertSame([RuleKind::ANNUAL_RATE], $rules->unavailableRuleKinds);
    }

    /**
     * A model that omitted the rate the catalogue still publishes does not
     * start following that publication again. The catalogue figure is
     * comparison for kinds the model already states; a kind it never carried
     * stays a gap, otherwise a later revision of that rate would silently
     * apply to every account on the model.
     */
    public function testACatalogueKindTheModelNeverCarriedStaysAGap(): void
    {
        $model = ProductModelFixture::model(
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling(
                    '00000000-0000-7000-8000-0000000000b1',
                    '25000.00',
                    '2025-04-25',
                    kind: RuleKind::DEPOSIT_CEILING,
                ),
            ]),
        );
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
            CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
        ]));

        $rules = AccountRules::fromModel(
            AccountFixture::account(productCode: null, productModelId: $model->id),
            $model->effectiveOn(ProductModelFixture::day('2026-09-02')),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            AccountRuleOverrides::none(),
        );

        self::assertSame(AccountRuleLayer::INHERITED, $rules->ceilings[0]->effectiveLayer);
        self::assertSame('25000.00', $rules->ceilings[0]->effective()->amount->value->toString());
        self::assertSame('22950.00', $rules->ceilings[0]->catalog?->amount->value->toString());
        self::assertSame([], $rules->rates);
        self::assertSame([RuleKind::ANNUAL_RATE], $rules->unavailableRuleKinds);
    }

    /**
     * A minimum rate is the contractual floor the kind exists to record. It
     * stays owed after the product leaves the catalogue; only yield-derived
     * rates fall back to not-guaranteed when no authority is left.
     */
    public function testAMinimumRateOverrideStaysGuaranteedAfterTheProductIsWithdrawn(): void
    {
        $rules = AccountRules::withWithdrawnProduct(
            AccountFixture::account(),
            CatalogFixture::day('2026-09-02'),
            new AccountRuleOverrides([
                AccountRuleOverrideFixture::rate(
                    '00000000-0000-7000-8000-0000000000c1',
                    '2026-01-01',
                    kind: RuleKind::MIN_RATE,
                ),
            ]),
        );

        self::assertTrue($rules->rates[0]->effective()->guaranteed);
    }

    /**
     * The three authorities at once, which is the shape a holder has to be
     * able to read: what the regulator publishes, what their bank's model
     * says, and what they recorded for this contract alone.
     */
    public function testTheThreeLayersAreReadableSideBySide(): void
    {
        $model = ProductModelFixture::model(
            wrapperKind: WrapperKind::REGULATED_SAVINGS,
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling(
                    '00000000-0000-7000-8000-0000000000b1',
                    '25000.00',
                    '2025-04-25',
                    kind: RuleKind::DEPOSIT_CEILING,
                ),
            ]),
        );
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
        ]));

        $rules = AccountRules::fromModel(
            AccountFixture::account(productCode: null, productModelId: $model->id),
            $model->effectiveOn(ProductModelFixture::day('2026-09-02')),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
            new AccountRuleOverrides([
                AccountRuleOverrideFixture::ceiling('00000000-0000-7000-8000-0000000000c1', '30000.00', '2026-01-01'),
            ]),
        );

        $ceiling = $rules->ceilings[0];
        self::assertSame(
            ['22950.00', '25000.00', '30000.00'],
            [
                $ceiling->catalog?->amount->value->toString(),
                $ceiling->inherited?->amount->value->toString(),
                $ceiling->override?->amount->value->toString(),
            ],
        );
        self::assertSame(AccountRuleLayer::OVERRIDE, $ceiling->effectiveLayer);
    }

    /**
     * An account that dropped its product keeps the claims recorded while it
     * had one. They are the only thing left that explains a past statement.
     */
    public function testAWithdrawnProductStillResolvesTheClaimsRecordedAgainstIt(): void
    {
        $rules = AccountRules::withWithdrawnProduct(
            AccountFixture::account(),
            CatalogFixture::day('2026-09-02'),
            new AccountRuleOverrides([
                AccountRuleOverrideFixture::ceiling('00000000-0000-7000-8000-0000000000c1', '30000.00', '2026-01-01'),
            ]),
        );

        self::assertSame(AccountRulesOrigin::PRODUCT_WITHDRAWN, $rules->origin);
        self::assertCount(1, $rules->ceilings);
        self::assertSame(AccountRuleLayer::OVERRIDE, $rules->ceilings[0]->effectiveLayer);
        self::assertNull($rules->ceilings[0]->catalog);
    }

    /**
     * @param list<\App\Module\Catalog\Domain\ProductRule> $rules
     */
    private function resolve(array $rules, string $asOf = '2026-09-02', ?AccountRuleOverrides $overrides = null): AccountRules
    {
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule($rules));

        return AccountRules::fromProduct(
            AccountFixture::account(),
            $entry->effectiveOn(CatalogFixture::day($asOf), CatalogFixture::day(self::TODAY)),
            $overrides ?? AccountRuleOverrides::none(),
        );
    }
}

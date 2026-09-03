<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountRules;
use App\Module\Accounts\Domain\AccountRulesOrigin;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\VerificationState;
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
        self::assertSame('22950.00', $rules->ceilings[0]->amount->value->toString());
        self::assertCount(1, $rules->rates);
        self::assertSame('1.7', $rules->rates[0]->scale->brackets[0]->percentage->toString());
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

        self::assertSame('2.4', $rules->rates[0]->scale->brackets[0]->percentage->toString());
    }

    /**
     * An open end means "in force until a revision closes it". Reading it as
     * an expiry would make a ceiling disappear the day after it was recorded.
     */
    public function testAnOpenEndedPeriodStaysInForceForEveryLaterDate(): void
    {
        $rules = $this->resolve([CatalogFixture::ceiling('22950.00', '2025-04-25')], asOf: '2099-12-31');

        self::assertCount(1, $rules->ceilings);
        self::assertNull($rules->ceilings[0]->period->validTo);
        self::assertSame('22950.00', $rules->ceilings[0]->amount->value->toString());
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
        self::assertFalse($ceiling->spansSeveralAccounts());
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
        );

        self::assertSame(
            [CeilingBasis::CONTRIBUTIONS, CeilingBasis::COMBINED_CONTRIBUTIONS],
            array_map(static fn ($ceiling) => $ceiling->basis, $rules->ceilings),
        );
        self::assertFalse($rules->ceilings[0]->spansSeveralAccounts());
        // Reading this plan alone can never settle the shared allowance: the
        // sibling plan holds the rest of it.
        self::assertTrue($rules->ceilings[1]->spansSeveralAccounts());
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
        );
        $dollars = AccountRules::fromProduct(
            AccountFixture::account(assetCode: 'USD'),
            $entry->effectiveOn(CatalogFixture::day('2026-09-02'), CatalogFixture::day(self::TODAY)),
        );

        self::assertTrue($euros->ceilings[0]->isMeasurable());
        self::assertFalse($dollars->ceilings[0]->isMeasurable());
    }

    public function testARegulatedRateIsOwedToTheHolderAndResolvesToOneBracket(): void
    {
        $rate = $this->resolve([CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31')])->rates[0];

        self::assertTrue($rate->guaranteed);
        self::assertSame(RateApplication::WHOLE_BALANCE, $rate->scale->application);
        self::assertCount(1, $rate->scale->brackets);
        self::assertSame('0', $rate->scale->brackets[0]->lowerBound->toString());
        self::assertNull($rate->scale->brackets[0]->upperBound);
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
        );

        $guaranteed = [];
        foreach ($rules->rates as $rate) {
            $guaranteed[$rate->kind->value] = $rate->guaranteed;
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
        );

        self::assertSame([], $rules->ceilings);
        self::assertSame([], $rules->rates);
        self::assertCount(1, $rules->terms);
        self::assertSame(RuleKind::ELIGIBILITY, $rules->terms[0]->kind);
        self::assertSame('NO_REGULATORY_CONTRIBUTION_CEILING', $rules->terms[0]->token);
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
        );

        self::assertSame(VerificationState::STALE, $rules->ceilings[0]->verification);
    }

    public function testAnAccountDescribedByHandInheritsNoRuleAndReportsNoGap(): void
    {
        $rules = AccountRules::withoutProduct(
            AccountFixture::account(productCode: null),
            CatalogFixture::day('2026-09-02'),
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
            ProductCode::fromString('FR_LIVRET_A'),
            CatalogFixture::day('2026-09-02'),
        );

        self::assertSame(AccountRulesOrigin::PRODUCT_WITHDRAWN, $rules->origin);
        self::assertSame('FR_LIVRET_A', $rules->productCode?->toString());
        self::assertSame([], $rules->ceilings);
    }

    /**
     * @param list<\App\Module\Catalog\Domain\ProductRule> $rules
     */
    private function resolve(array $rules, string $asOf = '2026-09-02'): AccountRules
    {
        $entry = new CatalogEntry(CatalogFixture::product(), new RuleSchedule($rules));

        return AccountRules::fromProduct(
            AccountFixture::account(),
            $entry->effectiveOn(CatalogFixture::day($asOf), CatalogFixture::day(self::TODAY)),
        );
    }
}

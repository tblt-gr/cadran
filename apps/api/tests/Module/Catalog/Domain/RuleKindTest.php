<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use PHPUnit\Framework\TestCase;

/**
 * The mapping this class protects is the one that decides whether a figure has
 * broken a rule. A deposit ceiling read against a total balance would refuse a
 * passbook that only earned interest, and a combined ceiling settled from one
 * account would refuse a plan whose sibling holds the rest of the allowance.
 */
final class RuleKindTest extends TestCase
{
    public function testEachCeilingNamesTheFigureItCaps(): void
    {
        self::assertSame(CeilingBasis::BALANCE_EXCLUDING_INTEREST, RuleKind::DEPOSIT_CEILING->ceilingBasis());
        self::assertSame(CeilingBasis::CONTRIBUTIONS, RuleKind::CONTRIBUTION_CEILING->ceilingBasis());
        self::assertSame(
            CeilingBasis::COMBINED_CONTRIBUTIONS,
            RuleKind::COMBINED_CONTRIBUTION_CEILING->ceilingBasis(),
        );
    }

    /**
     * The whole kind-to-measure table, asserted from the kinds themselves so a
     * kind added without a measure fails here rather than silently resolving to
     * "no ceiling", and a measure no kind produces cannot stay in the enum — or
     * in the published contract — as an answer the server can never give.
     */
    public function testEveryRuleKindNamesTheMeasureItCapsAndEveryMeasureHasAKind(): void
    {
        $expected = [
            RuleKind::DEPOSIT_CEILING->value => CeilingBasis::BALANCE_EXCLUDING_INTEREST,
            RuleKind::BALANCE_CEILING->value => CeilingBasis::TOTAL_BALANCE,
            RuleKind::CONTRIBUTION_CEILING->value => CeilingBasis::CONTRIBUTIONS,
            RuleKind::COMBINED_CONTRIBUTION_CEILING->value => CeilingBasis::COMBINED_CONTRIBUTIONS,
            RuleKind::ANNUAL_RATE->value => CeilingBasis::NONE,
            RuleKind::MIN_RATE->value => CeilingBasis::NONE,
            RuleKind::INTEREST_ACCRUAL_METHOD->value => CeilingBasis::NONE,
            RuleKind::ELIGIBILITY->value => CeilingBasis::NONE,
            RuleKind::TAX_REFERENCE->value => CeilingBasis::NONE,
        ];

        foreach (RuleKind::cases() as $kind) {
            self::assertArrayHasKey(
                $kind->value,
                $expected,
                sprintf('%s states no measure; a ceiling of that kind would be checked against nothing.', $kind->value),
            );
            self::assertSame($expected[$kind->value], $kind->ceilingBasis());
        }

        foreach (CeilingBasis::cases() as $basis) {
            self::assertContains(
                $basis,
                $expected,
                sprintf('No rule kind resolves to %s, so no account can ever be checked against it.', $basis->value),
            );
        }
    }

    public function testOnlyATotalBalanceAbsorbsCreditedInterest(): void
    {
        self::assertTrue(RuleKind::BALANCE_CEILING->ceilingBasis()->countsCreditedInterest());
        self::assertFalse(RuleKind::DEPOSIT_CEILING->ceilingBasis()->countsCreditedInterest());
        self::assertFalse(CeilingBasis::CONTRIBUTIONS->countsCreditedInterest());
        self::assertFalse(CeilingBasis::COMBINED_CONTRIBUTIONS->countsCreditedInterest());
    }

    public function testOnlyACombinedCeilingIsReachedAcrossSeveralAccounts(): void
    {
        self::assertTrue(CeilingBasis::COMBINED_CONTRIBUTIONS->spansSeveralAccounts());
        self::assertFalse(CeilingBasis::CONTRIBUTIONS->spansSeveralAccounts());
        self::assertFalse(CeilingBasis::BALANCE_EXCLUDING_INTEREST->spansSeveralAccounts());
    }

    public function testARuleThatCapsNothingStatesNoBasis(): void
    {
        foreach ([RuleKind::ANNUAL_RATE, RuleKind::INTEREST_ACCRUAL_METHOD, RuleKind::ELIGIBILITY] as $kind) {
            self::assertFalse($kind->statesACeiling());
            self::assertSame(CeilingBasis::NONE, $kind->ceilingBasis());
        }
    }

    /**
     * Whether a rate is owed to the holder is decided by the rule kind and the
     * yield together. A minimum rate is the contractual floor the institution
     * committed to and stays owed on a revisable contract, while the annual
     * rate published beside it is only the rate of the day.
     */
    public function testAMinimumRateIsOwedToTheHolderWhateverTheYieldPublishesBesideIt(): void
    {
        self::assertTrue(RuleKind::MIN_RATE->rateIsOwedToTheHolder(YieldKind::CONTRACTUAL_VARIABLE));
        self::assertFalse(RuleKind::ANNUAL_RATE->rateIsOwedToTheHolder(YieldKind::CONTRACTUAL_VARIABLE));
    }

    public function testARegulatedOrFixedYieldOwesTheRateItPublishes(): void
    {
        self::assertTrue(RuleKind::ANNUAL_RATE->rateIsOwedToTheHolder(YieldKind::REGULATED_RATE));
        self::assertTrue(RuleKind::ANNUAL_RATE->rateIsOwedToTheHolder(YieldKind::CONTRACTUAL_FIXED));
    }

    /**
     * The envelope answers whatever the business date: a plan is capped on its
     * contributions even on a day no sourced amount covers.
     */
    public function testAnEnvelopeStatesItsBasisWithoutAnySourcedRule(): void
    {
        self::assertSame(
            CeilingBasis::BALANCE_EXCLUDING_INTEREST,
            WrapperKind::REGULATED_SAVINGS->ceilingBasis(),
        );
        self::assertSame(CeilingBasis::CONTRIBUTIONS, WrapperKind::TAX_WRAPPER->ceilingBasis());
        self::assertSame(CeilingBasis::NONE, WrapperKind::SECURITIES_ACCOUNT->ceilingBasis());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountRuleOverrides;
use App\Module\Accounts\Domain\InvalidAccountRuleOverride;
use App\Module\Accounts\Domain\OverlappingAccountRuleOverride;
use App\Module\Catalog\Domain\RuleKind;
use PHPUnit\Framework\TestCase;

/**
 * The set of claims one account carries. What it exists to guarantee is that
 * "the ceiling this account claims on 12 March" has one answer or none.
 */
final class AccountRuleOverridesTest extends TestCase
{
    private const string FIRST = '00000000-0000-7000-8000-0000000000c1';
    private const string SECOND = '00000000-0000-7000-8000-0000000000c2';

    public function testTwoStandingOverridesOfTheSameKindCannotCoverTheSameDay(): void
    {
        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('cover the same dates');

        new AccountRuleOverrides([
            AccountRuleOverrideFixture::ceiling(self::FIRST, '30000.00', '2026-01-01', '2026-06-30'),
            AccountRuleOverrideFixture::ceiling(self::SECOND, '31000.00', '2026-06-30'),
        ]);
    }

    /**
     * Two kinds are two different questions, so they never collide even over
     * exactly the same dates.
     */
    public function testTwoKindsMayCoverTheSameDates(): void
    {
        $overrides = new AccountRuleOverrides([
            AccountRuleOverrideFixture::ceiling(self::FIRST, '30000.00', '2026-01-01'),
            AccountRuleOverrideFixture::rate(self::SECOND, '2026-01-01'),
        ]);

        self::assertCount(2, $overrides->effectiveOn(ProductModelFixture::day('2026-06-01')));
    }

    /**
     * Withdrawing frees the dates it claimed. Re-recording the corrected
     * period over the same days is the normal next step, and would be
     * impossible if a withdrawn claim still reserved them.
     */
    public function testAWithdrawnOverrideNoLongerReservesItsDates(): void
    {
        $overrides = new AccountRuleOverrides([
            AccountRuleOverrideFixture::ceiling(
                self::FIRST,
                '30000.00',
                '2026-01-01',
                withdrawnAt: new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
            ),
        ]);

        $corrected = $overrides->appended(
            AccountRuleOverrideFixture::ceiling(self::SECOND, '31000.00', '2026-01-01'),
        );

        self::assertCount(2, $corrected->overrides);
        self::assertCount(1, $corrected->standing());
        $inForce = $corrected->effectiveOn(ProductModelFixture::day('2026-06-01'));
        self::assertSame('31000.00', $inForce[RuleKind::DEPOSIT_CEILING->value]->value->amount?->value->toString());
    }

    /**
     * Unlike a product model, nothing is closed on the holder's behalf.
     * Silently ending the previous claim would rewrite what the account said
     * about a past date without anybody deciding to.
     */
    public function testAppendingOverAStandingClaimIsRefusedRatherThanClosingIt(): void
    {
        $overrides = new AccountRuleOverrides([
            AccountRuleOverrideFixture::ceiling(self::FIRST, '30000.00', '2026-01-01'),
        ]);

        $this->expectException(OverlappingAccountRuleOverride::class);
        $this->expectExceptionMessage('Withdraw it');

        $overrides->appended(AccountRuleOverrideFixture::ceiling(self::SECOND, '31000.00', '2026-06-01'));
    }

    public function testWithdrawingReplacesTheClaimAndKeepsEverythingElse(): void
    {
        $overrides = new AccountRuleOverrides([
            AccountRuleOverrideFixture::ceiling(self::FIRST, '30000.00', '2026-01-01'),
            AccountRuleOverrideFixture::rate(self::SECOND, '2026-01-01'),
        ]);

        $withdrawn = $overrides->withdrawn(
            self::FIRST,
            AccountRuleOverrideFixture::OTHER_AUTHOR,
            new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
        );

        self::assertCount(2, $withdrawn->overrides);
        self::assertFalse($withdrawn->find(self::FIRST)?->isStanding());
        self::assertTrue($withdrawn->find(self::SECOND)?->isStanding());
    }

    public function testAnUnknownIdentifierCannotBeWithdrawn(): void
    {
        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('No override carries this identifier');

        AccountRuleOverrides::none()->withdrawn(
            self::FIRST,
            AccountRuleOverrideFixture::AUTHOR,
            new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
        );
    }

    /**
     * An account is a small set of local corrections, not a history table.
     * Without the bound one account could turn every rule resolution into an
     * unbounded read.
     */
    public function testTheNumberOfOverridesIsBounded(): void
    {
        $overrides = [];
        for ($index = 0; $index <= AccountRuleOverrides::MAX_OVERRIDES; ++$index) {
            $overrides[] = AccountRuleOverrideFixture::ceiling(
                sprintf('00000000-0000-7000-8000-%012x', $index),
                '30000.00',
                '2026-01-01',
                withdrawnAt: new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
            );
        }

        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('at most');

        new AccountRuleOverrides($overrides);
    }
}

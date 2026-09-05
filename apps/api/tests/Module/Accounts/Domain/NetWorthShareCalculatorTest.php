<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorthShareCalculator;
use App\Module\Accounts\Domain\NetWorthShareReason;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * Hand-checked reference for account and exclusive group shares.
 *
 * Eligible net worth is +10 000 EUR:
 *   Checking  +4 000, primary Liquidités
 *   Livret    +6 000, primary Épargne, tag Liquidités
 *   Piggy     +1 000, excluded, primary Épargne
 *
 * Exclusive weights ignore the Livret tag, so Liquidités is 40 % and
 * Épargne is 60 %. The excluded piggy never enters either side.
 */
final class NetWorthShareCalculatorTest extends TestCase
{
    private const string CHECKING = '00000000-0000-7000-8000-0000000000d1';
    private const string LIVRET = '00000000-0000-7000-8000-0000000000d2';
    private const string PIGGY = '00000000-0000-7000-8000-0000000000d3';
    private const string LOAN = '00000000-0000-7000-8000-0000000000d4';
    private const string LIQUID = '00000000-0000-7000-8000-0000000000b1';
    private const string SAVINGS = '00000000-0000-7000-8000-0000000000b2';
    private const string DEBTS = '00000000-0000-7000-8000-0000000000b3';

    public function testAccountShareIsTheSignedValueOverEligibleNetWorth(): void
    {
        $shares = NetWorthShareCalculator::compute($this->positiveExample(), $this->lineages());

        self::assertSame('0.400000000000000000000000', $shares->account(self::CHECKING)->ratio?->toString());
        self::assertSame('40.000000000000000000000000', $shares->account(self::CHECKING)->percent?->toString());
        self::assertNull($shares->account(self::CHECKING)->reason);
        self::assertSame('0.600000000000000000000000', $shares->account(self::LIVRET)->ratio?->toString());
        self::assertSame('60.000000000000000000000000', $shares->account(self::LIVRET)->percent?->toString());
    }

    public function testATagNeverEntersTheExclusiveGroupDenominator(): void
    {
        $shares = NetWorthShareCalculator::compute($this->positiveExample(), $this->lineages());

        self::assertSame('0.400000000000000000000000', $shares->group(self::LIQUID)->ratio?->toString());
        self::assertSame('0.600000000000000000000000', $shares->group(self::SAVINGS)->ratio?->toString());
        self::assertNull($shares->account(self::PIGGY)->ratio);
        self::assertNull($shares->account(self::PIGGY)->reason);
    }

    public function testAZeroEligibleNetWorthMakesEveryIncludedShareNull(): void
    {
        $shares = NetWorthShareCalculator::compute([
            $this->valued(self::CHECKING, self::LIQUID, '1000'),
            $this->valued(self::LOAN, self::DEBTS, '1000', sign: -1),
        ], $this->lineages());

        self::assertNull($shares->account(self::CHECKING)->ratio);
        self::assertSame(NetWorthShareReason::ZERO_ELIGIBLE_NET_WORTH, $shares->account(self::CHECKING)->reason);
        self::assertNull($shares->account(self::CHECKING)->percent);
        self::assertNull($shares->group(self::LIQUID)->ratio);
        self::assertSame(NetWorthShareReason::ZERO_ELIGIBLE_NET_WORTH, $shares->group(self::LIQUID)->reason);
    }

    public function testANegativeEligibleNetWorthMakesEveryIncludedShareNull(): void
    {
        $shares = NetWorthShareCalculator::compute([
            $this->valued(self::CHECKING, self::LIQUID, '1000'),
            $this->valued(self::LOAN, self::DEBTS, '2000', sign: -1),
        ], $this->lineages());

        self::assertNull($shares->account(self::CHECKING)->ratio);
        self::assertSame(NetWorthShareReason::NEGATIVE_ELIGIBLE_NET_WORTH, $shares->account(self::CHECKING)->reason);
        self::assertNull($shares->group(self::DEBTS)->percent);
        self::assertSame(NetWorthShareReason::NEGATIVE_ELIGIBLE_NET_WORTH, $shares->group(self::DEBTS)->reason);
    }

    public function testAMissingValuationMakesEveryIncludedShareNull(): void
    {
        $shares = NetWorthShareCalculator::compute([
            $this->valued(self::CHECKING, self::LIQUID, '4000'),
            new AccountShareInput(
                accountId: self::LOAN,
                includeInNetWorth: true,
                netWorthSign: -1,
                primaryGroupId: self::DEBTS,
                tagGroupIds: [],
                value: null,
            ),
        ], $this->lineages());

        self::assertNull($shares->account(self::CHECKING)->ratio);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $shares->account(self::CHECKING)->reason);
        self::assertNull($shares->group(self::LIQUID)->ratio);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $shares->group(self::LIQUID)->reason);
    }

    public function testAParentGroupShareRollsUpExclusiveDescendantsOnce(): void
    {
        $parent = '00000000-0000-7000-8000-0000000000b4';
        $child = self::SAVINGS;
        $shares = NetWorthShareCalculator::compute($this->positiveExample(), [
            new GroupLineage(self::LIQUID, [self::LIQUID]),
            new GroupLineage($child, [$child, $parent]),
            new GroupLineage($parent, [$parent]),
            new GroupLineage(self::DEBTS, [self::DEBTS]),
        ]);

        self::assertSame('0.600000000000000000000000', $shares->group($child)->ratio?->toString());
        self::assertSame('0.600000000000000000000000', $shares->group($parent)->ratio?->toString());
    }

    /** @return list<AccountShareInput> */
    private function positiveExample(): array
    {
        return [
            $this->valued(self::CHECKING, self::LIQUID, '4000'),
            $this->valued(self::LIVRET, self::SAVINGS, '6000', tags: [self::LIQUID]),
            $this->valued(self::PIGGY, self::SAVINGS, '1000', included: false),
        ];
    }

    /** @return list<GroupLineage> */
    private function lineages(): array
    {
        return [
            new GroupLineage(self::LIQUID, [self::LIQUID]),
            new GroupLineage(self::SAVINGS, [self::SAVINGS]),
            new GroupLineage(self::DEBTS, [self::DEBTS]),
        ];
    }

    /**
     * @param list<string> $tags
     */
    private function valued(
        string $id,
        string $primary,
        string $value,
        int $sign = 1,
        bool $included = true,
        array $tags = [],
    ): AccountShareInput {
        return new AccountShareInput(
            accountId: $id,
            includeInNetWorth: $included,
            netWorthSign: $sign,
            primaryGroupId: $primary,
            tagGroupIds: $tags,
            value: DecimalValue::fromString($value),
        );
    }
}

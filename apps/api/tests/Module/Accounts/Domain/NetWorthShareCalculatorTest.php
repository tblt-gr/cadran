<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\ExclusiveGroupRollup;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorthShareCalculator;
use App\Module\Accounts\Domain\NetWorthShareReason;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;
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
    private const string INVEST = '00000000-0000-7000-8000-0000000000b5';
    private const string REAL_ESTATE = '00000000-0000-7000-8000-0000000000b6';
    private const string STOCKS = '00000000-0000-7000-8000-0000000000b7';
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
                value: null,
                asset: null,
            ),
        ], $this->lineages());

        self::assertNull($shares->account(self::CHECKING)->ratio);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $shares->account(self::CHECKING)->reason);
        self::assertNull($shares->group(self::LIQUID)->ratio);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $shares->group(self::LIQUID)->reason);
    }

    public function testTwoAssetsMakeEveryIncludedShareNullRatherThanADenominator(): void
    {
        $shares = NetWorthShareCalculator::compute([
            $this->valued(self::CHECKING, self::LIQUID, '4000'),
            $this->valued(self::LIVRET, self::SAVINGS, '3.00000000', asset: 'BTC'),
        ], $this->lineages());

        self::assertNull($shares->account(self::CHECKING)->percent);
        self::assertSame(NetWorthShareReason::MIXED_ASSETS, $shares->account(self::CHECKING)->reason);
        self::assertNull($shares->group(self::LIQUID)->percent);
        self::assertSame(NetWorthShareReason::MIXED_ASSETS, $shares->group(self::LIQUID)->reason);
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

    /**
     * Eligible net worth is 1 000: A 600 in Épargne (child of Liquidités),
     * B 300 directly in Liquidités, C 100 without group and D 50 excluded.
     * Épargne is 60 %, Liquidités rolls A and B up to 90 %, the ungrouped
     * section is 10 %.
     */
    public function testTheUngroupedSectionIsAnExclusiveBucketOfTheSameDenominator(): void
    {
        $inputs = [
            $this->valued(self::CHECKING, self::SAVINGS, '600'),
            $this->valued(self::LIVRET, self::LIQUID, '300'),
            new AccountShareInput(self::LOAN, true, 1, null, DecimalValue::fromString('100'), AssetCode::fromString('EUR')),
            new AccountShareInput(self::PIGGY, false, 1, null, DecimalValue::fromString('50'), AssetCode::fromString('EUR')),
        ];
        $lineages = [
            new GroupLineage(self::LIQUID, [self::LIQUID]),
            new GroupLineage(self::SAVINGS, [self::SAVINGS, self::LIQUID]),
        ];

        $shares = NetWorthShareCalculator::compute($inputs, $lineages);

        self::assertSame('60.000000000000000000000000', $shares->group(self::SAVINGS)->percent?->toString());
        self::assertSame('90.000000000000000000000000', $shares->group(self::LIQUID)->percent?->toString());
        self::assertSame('10.000000000000000000000000', $shares->ungrouped()->percent?->toString());
        self::assertSame('100', ExclusiveGroupRollup::ungroupedTotal($inputs)->toString());
        self::assertSame('30.000000000000000000000000', $shares->account(self::LIVRET)->percent?->toString());
    }

    public function testTheUngroupedShareFollowsTheNullWithReasonPolicy(): void
    {
        $shares = NetWorthShareCalculator::compute([
            $this->valued(self::CHECKING, self::LIQUID, '1000'),
            new AccountShareInput(self::LOAN, true, -1, null, DecimalValue::fromString('1000'), AssetCode::fromString('EUR')),
        ], $this->lineages());

        self::assertNull($shares->ungrouped()->percent);
        self::assertSame(NetWorthShareReason::ZERO_ELIGIBLE_NET_WORTH, $shares->ungrouped()->reason);
    }

    /**
     * Investissement holds 100 itself, Immobilier 300 and Bourse 200 below it,
     * 50 sits in no group. Eligible net worth is 650. Direct totals are 100,
     * 300, 200 and 50 (sum 650); the root roll-up is 600 + 50 = 650.
     * Direct shares: 15.38 %, 46.15 %, 30.77 %, ungrouped 7.69 %.
     */
    public function testDirectTotalsAndSharesPartitionEligibleNetWorth(): void
    {
        [$inputs, $lineages] = $this->investmentTree();

        $direct = ExclusiveGroupRollup::directTotals($inputs, $lineages);
        self::assertSame('100', $direct[self::INVEST]->toString());
        self::assertSame('300', $direct[self::REAL_ESTATE]->toString());
        self::assertSame('200', $direct[self::STOCKS]->toString());
        self::assertSame('600', ExclusiveGroupRollup::totals(array_values(array_filter($inputs, static fn (AccountShareInput $i): bool => $i->includeInNetWorth)), $lineages)[self::INVEST]->toString());

        $shares = NetWorthShareCalculator::compute($inputs, $lineages);
        self::assertSame('15.38', $this->display($shares->directGroup(self::INVEST)->percent?->toString()));
        self::assertSame('46.15', $this->display($shares->directGroup(self::REAL_ESTATE)->percent?->toString()));
        self::assertSame('30.77', $this->display($shares->directGroup(self::STOCKS)->percent?->toString()));
        self::assertSame('7.69', $this->display($shares->ungrouped()->percent?->toString()));
        self::assertSame('92.31', $this->display($shares->group(self::INVEST)->percent?->toString()));
    }

    public function testADirectTotalIgnoresExcludedAndUnvaluedAccounts(): void
    {
        $inputs = [
            $this->valued(self::CHECKING, self::LIQUID, '10'),
            $this->valued(self::PIGGY, self::LIQUID, '99', included: false),
            new AccountShareInput(self::LIVRET, true, 1, self::LIQUID, null, null),
        ];
        $direct = ExclusiveGroupRollup::directTotals($inputs, [new GroupLineage(self::LIQUID, [self::LIQUID])]);

        self::assertSame('10', $direct[self::LIQUID]->toString());
    }

    public function testADirectLiabilityIsNegativeAndAGroupWithoutDirectAccountIsZero(): void
    {
        [$inputs, $lineages] = $this->investmentTree();
        $inputs[] = $this->valued(self::LOAN, self::STOCKS, '50', sign: -1);

        $direct = ExclusiveGroupRollup::directTotals($inputs, $lineages);
        self::assertSame('150', $direct[self::STOCKS]->toString());

        $empty = ExclusiveGroupRollup::directTotals([], $lineages);
        self::assertSame('0', $empty[self::INVEST]->toString());
    }

    public function testDirectSharesFollowTheNullWithReasonPolicy(): void
    {
        [$inputs, $lineages] = $this->investmentTree();
        $inputs[] = new AccountShareInput(self::PIGGY, true, 1, self::STOCKS, null, null);

        $shares = NetWorthShareCalculator::compute($inputs, $lineages);

        self::assertNull($shares->directGroup(self::STOCKS)->percent);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $shares->directGroup(self::STOCKS)->reason);
        self::assertNull($shares->directGroup('unknown')->reason);
    }

    public function testDirectTotalsAndRootRollupsBothPartitionEligibleNetWorthOnRandomTrees(): void
    {
        mt_srand(20261028);
        for ($round = 0; $round < 40; ++$round) {
            $lineages = [];
            $ids = [];
            $roots = [];
            for ($i = 0, $count = mt_rand(1, 25); $i < $count; ++$i) {
                $id = sprintf('00000000-0000-7000-8000-%012d', $i + 1);
                $parent = [] === $ids || 0 === mt_rand(0, 3) ? null : $ids[mt_rand(0, count($ids) - 1)];
                $path = null === $parent ? [] : $lineages[$parent]->ancestorIdsIncludingSelf;
                if (count($path) >= 8) {
                    $parent = null;
                    $path = [];
                }
                $lineages[$id] = new GroupLineage($id, [$id, ...$path]);
                $ids[] = $id;
                if (null === $parent) {
                    $roots[] = $id;
                }
            }

            $inputs = [];
            $eligible = DecimalValue::fromString('0');
            for ($j = 0, $n = mt_rand(0, 40); $j < $n; ++$j) {
                $group = 0 === mt_rand(0, 4) ? null : $ids[mt_rand(0, count($ids) - 1)];
                $value = sprintf('%d.%06d', mt_rand(0, 1_000_000), mt_rand(0, 999_999));
                $sign = 0 === mt_rand(0, 2) ? -1 : 1;
                $inputs[] = new AccountShareInput(sprintf('a%d', $j), true, $sign, $group, DecimalValue::fromString($value), AssetCode::fromString('EUR'));
                $eligible = ExactDecimal::add($eligible, ExactDecimal::signed(DecimalValue::fromString($value), $sign));
            }

            $lineageList = array_values($lineages);
            $ungrouped = ExclusiveGroupRollup::ungroupedTotal($inputs);
            $directSum = $ungrouped;
            foreach (ExclusiveGroupRollup::directTotals($inputs, $lineageList) as $total) {
                $directSum = ExactDecimal::add($directSum, $total);
            }
            $rollupSum = $ungrouped;
            $rollups = ExclusiveGroupRollup::totals($inputs, $lineageList);
            foreach ($roots as $root) {
                $rollupSum = ExactDecimal::add($rollupSum, $rollups[$root]);
            }

            self::assertTrue(ExactDecimal::isZero(ExactDecimal::subtract($eligible, $directSum)), 'direct partition, round '.$round);
            self::assertTrue(ExactDecimal::isZero(ExactDecimal::subtract($eligible, $rollupSum)), 'root partition, round '.$round);
        }
    }

    /** @return array{list<AccountShareInput>, list<GroupLineage>} */
    private function investmentTree(): array
    {
        return [
            [
                $this->valued(self::CHECKING, self::INVEST, '100'),
                $this->valued(self::LIVRET, self::REAL_ESTATE, '300'),
                $this->valued('00000000-0000-7000-8000-0000000000d5', self::STOCKS, '200'),
                new AccountShareInput(self::LOAN, true, 1, null, DecimalValue::fromString('50'), AssetCode::fromString('EUR')),
                $this->valued(self::PIGGY, self::STOCKS, '999', included: false),
            ],
            [
                new GroupLineage(self::INVEST, [self::INVEST]),
                new GroupLineage(self::REAL_ESTATE, [self::REAL_ESTATE, self::INVEST]),
                new GroupLineage(self::STOCKS, [self::STOCKS, self::INVEST]),
            ],
        ];
    }

    private function display(?string $percent): ?string
    {
        return null === $percent ? null : ExactDecimal::round(DecimalValue::fromString($percent), 2, RoundingMode::HALF_UP)->toString();
    }

    /** @return list<AccountShareInput> */
    private function positiveExample(): array
    {
        return [
            $this->valued(self::CHECKING, self::LIQUID, '4000'),
            $this->valued(self::LIVRET, self::SAVINGS, '6000'),
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

    private function valued(
        string $id,
        string $primary,
        string $value,
        int $sign = 1,
        bool $included = true,
        string $asset = 'EUR',
    ): AccountShareInput {
        return new AccountShareInput(
            accountId: $id,
            includeInNetWorth: $included,
            netWorthSign: $sign,
            primaryGroupId: $primary,
            value: DecimalValue::fromString($value),
            asset: AssetCode::fromString($asset),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\GroupCompositionCalculator;
use App\Module\Accounts\Domain\GroupCompositionChartReason;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorthShareReason;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * Hand-computed reference. Investissement holds Compte espèces 100 itself,
 * Bourse (child) holds PEA 900 and Immobilier (child) holds SCPI 500:
 * total 1500, Bourse 900/1500 = 60 %, Immobilier 500/1500 = 33.33...%,
 * Comptes directs 100/1500 = 6.66...%. With a liability of 200 in Immobilier
 * the total is 1300 and Immobilier is 300.
 */
final class GroupCompositionCalculatorTest extends TestCase
{
    private const string ROOT = '00000000-0000-7000-8000-0000000000a0';
    private const string BOURSE = '00000000-0000-7000-8000-0000000000a1';
    private const string IMMO = '00000000-0000-7000-8000-0000000000a2';

    public function testTheSubgroupsAndTheDirectEntryPartitionTheTotalExactly(): void
    {
        $composition = GroupCompositionCalculator::compute(self::ROOT, $this->accounts(), $this->lineages(), [self::BOURSE, self::IMMO]);

        self::assertSame('1500', $composition->total?->toString());
        self::assertNull($composition->totalReason);
        self::assertNull($composition->chartReason);
        self::assertSame('0.600000000000000000000000', $composition->subgroups[self::BOURSE]->share->ratio?->toString());
        self::assertSame('0.333333333333333333333333', $composition->subgroups[self::IMMO]->share->ratio?->toString());
        self::assertSame('0.066666666666666666666667', $composition->direct?->share->ratio?->toString());
        self::assertSame('100', $composition->direct->value?->toString());
        self::assertSame('0.600000000000000000000000', $composition->accounts['pea']->share->ratio?->toString());
        self::assertSame(['cash', 'pea', 'scpi'], array_keys($composition->accounts));
    }

    public function testALiabilityKeepsTheSharesButWarnsTheChart(): void
    {
        $composition = GroupCompositionCalculator::compute(
            self::ROOT,
            [...$this->accounts(), $this->account('loan', self::IMMO, '200', -1)],
            $this->lineages(),
            [self::BOURSE, self::IMMO],
        );

        self::assertSame('1300', $composition->total?->toString());
        self::assertSame(GroupCompositionChartReason::NEGATIVE_COMPONENT, $composition->chartReason);
        self::assertSame('300', $composition->subgroups[self::IMMO]->value?->toString());
        self::assertSame('-200', $composition->accounts['loan']->value?->toString());
        self::assertSame('-0.153846153846153846153846', $composition->accounts['loan']->share->ratio?->toString());
    }

    public function testAZeroTotalRefusesEveryShare(): void
    {
        $composition = GroupCompositionCalculator::compute(
            self::ROOT,
            [$this->account('a', self::BOURSE, '100'), $this->account('b', self::IMMO, '100', -1)],
            $this->lineages(),
            [self::BOURSE, self::IMMO],
        );

        self::assertSame('0', $composition->total?->toString());
        self::assertSame(GroupCompositionChartReason::ZERO_GROUP_TOTAL, $composition->chartReason);
        self::assertSame(NetWorthShareReason::ZERO_GROUP_TOTAL, $composition->accounts['a']->share->reason);
        self::assertNull($composition->accounts['a']->share->ratio);
        self::assertSame('100', $composition->accounts['a']->value?->toString());
    }

    public function testANegativeTotalRefusesEveryShare(): void
    {
        $composition = GroupCompositionCalculator::compute(
            self::ROOT,
            [$this->account('a', self::BOURSE, '100'), $this->account('b', self::IMMO, '300', -1)],
            $this->lineages(),
            [self::BOURSE, self::IMMO],
        );

        self::assertSame(GroupCompositionChartReason::NEGATIVE_GROUP_TOTAL, $composition->chartReason);
        self::assertSame(NetWorthShareReason::NEGATIVE_GROUP_TOTAL, $composition->subgroups[self::IMMO]->share->reason);
    }

    public function testAMissingValuationBlocksTheTotalButKeepsKnownValues(): void
    {
        $composition = GroupCompositionCalculator::compute(
            self::ROOT,
            [$this->account('a', self::BOURSE, '100'), $this->account('b', self::IMMO, null)],
            $this->lineages(),
            [self::BOURSE, self::IMMO],
        );

        self::assertNull($composition->total);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $composition->totalReason);
        self::assertSame(GroupCompositionChartReason::MISSING_VALUATION, $composition->chartReason);
        self::assertNull($composition->subgroups[self::IMMO]->value);
        self::assertNull($composition->subgroups[self::BOURSE]->value);
        self::assertSame('100', $composition->accounts['a']->value?->toString());
        self::assertNull($composition->accounts['a']->share->ratio);
        self::assertSame(NetWorthShareReason::MISSING_VALUATION, $composition->accounts['b']->share->reason);
    }

    public function testMixedAssetsBlockEverySubgroupValue(): void
    {
        $composition = GroupCompositionCalculator::compute(
            self::ROOT,
            [$this->account('a', self::BOURSE, '100'), $this->account('b', self::IMMO, '1', 1, 'BTC')],
            $this->lineages(),
            [self::BOURSE, self::IMMO],
        );

        self::assertNull($composition->total);
        self::assertNull($composition->asset);
        self::assertSame(GroupCompositionChartReason::MIXED_ASSETS, $composition->chartReason);
        self::assertNull($composition->subgroups[self::BOURSE]->value);
        self::assertSame('1', $composition->accounts['b']->value?->toString());
    }

    public function testAGroupWithoutEligibleAccountIsAZeroTotalWithNoEntry(): void
    {
        $composition = GroupCompositionCalculator::compute(self::ROOT, [], $this->lineages(), [self::BOURSE, self::IMMO]);

        self::assertSame('0', $composition->total?->toString());
        self::assertSame(GroupCompositionChartReason::ZERO_GROUP_TOTAL, $composition->chartReason);
        self::assertSame([], $composition->subgroups);
        self::assertNull($composition->direct);
        self::assertSame([], $composition->accounts);
    }

    public function testVeryLargeDecimalsStayExact(): void
    {
        $large = '99999999999999999999999.999999999999999999999999';
        $composition = GroupCompositionCalculator::compute(
            self::ROOT,
            [$this->account('a', self::BOURSE, $large), $this->account('b', self::IMMO, $large)],
            $this->lineages(),
            [self::BOURSE, self::IMMO],
        );

        self::assertSame('199999999999999999999999.999999999999999999999998', $composition->total?->toString());
        self::assertSame('0.500000000000000000000000', $composition->accounts['a']->share->ratio?->toString());
    }

    /** @return list<AccountShareInput> */
    private function accounts(): array
    {
        return [
            $this->account('cash', self::ROOT, '100'),
            $this->account('pea', self::BOURSE, '900'),
            $this->account('scpi', self::IMMO, '500'),
        ];
    }

    private function account(string $id, string $group, ?string $value, int $sign = 1, string $asset = 'EUR'): AccountShareInput
    {
        return new AccountShareInput(
            accountId: $id,
            includeInNetWorth: true,
            netWorthSign: $sign,
            primaryGroupId: $group,
            value: null === $value ? null : DecimalValue::fromString($value),
            asset: AssetCode::fromString($asset),
        );
    }

    /** @return list<GroupLineage> */
    private function lineages(): array
    {
        return [
            new GroupLineage(self::ROOT, [self::ROOT]),
            new GroupLineage(self::BOURSE, [self::BOURSE, self::ROOT]),
            new GroupLineage(self::IMMO, [self::IMMO, self::ROOT]),
        ];
    }
}

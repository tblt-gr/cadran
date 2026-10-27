<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\CurrentGroupTree;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Reporting\Application\AccountWeight;
use App\Module\Reporting\Application\AllocationFigure;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\RegroupMonthFigures;
use App\Tests\Module\Reporting\Application\Double\FixedGroupTree;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

/**
 * Hand-computed example. Accounts: A1 shares 1000.00, A2 flat 500.50, A3 loan -200.25 (liability),
 * A4 an excluded 9999. Eligible net worth = 1000.00 + 500.50 - 200.25 = 1300.25.
 * Tree: Investissement (I) > Bourse (B), Immobilier (M); Epargne (E) is a second, empty root.
 */
final class RegroupMonthFiguresTest extends TestCase
{
    private const string I = 'g-invest';
    private const string B = 'g-bourse';
    private const string M = 'g-immo';
    private const string E = 'g-epargne';

    public function testAClosedMonthIsRegroupedWhenAnAccountMovesToASubGroup(): void
    {
        // Frozen while A1 still sat in Investissement: I held 1300.25, B and M were empty.
        $frozen = $this->figures([
            new AccountWeight('a1', '1000.00', 'EUR', true),
            new AccountWeight('a2', '500.50', 'EUR', true),
            new AccountWeight('a3', '-200.25', 'EUR', true),
            new AccountWeight('a4', '9999', 'EUR', false),
        ], ['group:'.self::I => '1300.25', 'group:'.self::B => '0', 'group:'.self::M => '500.50']);

        $regrouped = $this->regroup($frozen, ['a1' => self::B, 'a2' => self::M, 'a3' => self::I, 'a4' => self::B]);

        self::assertSame('1000.00', $regrouped->cells['group:'.self::B]->value);
        self::assertSame('500.50', $regrouped->cells['group:'.self::M]->value);
        // Investissement is the sum of itself and its sub-groups: 1000.00 + 500.50 - 200.25.
        self::assertSame('1300.25', $regrouped->cells['group:'.self::I]->value);
        self::assertSame('EUR', $regrouped->cells['group:'.self::I]->assetCode);
        // The excluded 9999 never weighs, and an empty group reads zero, not null.
        self::assertSame('0', $regrouped->cells['group:'.self::E]->value);
        self::assertSame('EUR', $regrouped->cells['group:'.self::E]->assetCode);
        self::assertNull($regrouped->cells['group:'.self::E]->reason);
        // A group that no longer exists leaves no cell behind.
        self::assertArrayNotHasKey('group:stale', $regrouped->cells);
        // Frozen account values and the net worth never move.
        self::assertSame('1300.25', $regrouped->cells['endNetWorth']->value);
        self::assertSame('1000.00', $regrouped->cells['account:a1']->value);
        self::assertSame($frozen->accountWeights, $regrouped->accountWeights);
    }

    public function testTheAllocationFollowsTheCurrentTreeInDepthFirstOrderWithExactShares(): void
    {
        $regrouped = $this->regroup($this->figures([
            new AccountWeight('a1', '1000.00', 'EUR', true),
            new AccountWeight('a2', '500.50', 'EUR', true),
            new AccountWeight('a3', '-200.25', 'EUR', true),
        ], []), ['a1' => self::B, 'a2' => self::M, 'a3' => self::I]);

        self::assertSame([self::I, self::B, self::M, self::E], array_map(static fn (AllocationFigure $item): string => $item->groupId, $regrouped->allocation));
        $byGroup = [];
        foreach ($regrouped->allocation as $item) {
            $byGroup[$item->groupId] = $item;
        }
        self::assertSame('1.000000000000000000000000', $byGroup[self::I]->share);
        self::assertSame('0.000000000000000000000000', $byGroup[self::E]->share);
        // 1000.00 / 1300.25 = 0.76908287...
        self::assertNotNull($byGroup[self::B]->share);
        self::assertStringStartsWith('0.769082', $byGroup[self::B]->share);
        self::assertNull($byGroup[self::B]->reason);
        self::assertSame('1000.00', $byGroup[self::B]->value);
    }

    public function testAGroupMovedUnderAnotherParentRollsUpIntoItsNewAncestors(): void
    {
        $tree = new CurrentGroupTree([
            new GroupLineage(self::E, [self::E]),
            new GroupLineage(self::I, [self::I, self::E]),
            new GroupLineage(self::B, [self::B, self::I, self::E]),
        ], ['a1' => self::B]);
        $regrouped = $this->regroupWith($this->figures([new AccountWeight('a1', '40', 'EUR', true)], []), $tree);

        self::assertSame('40', $regrouped->cells['group:'.self::E]->value);
        self::assertSame('40', $regrouped->cells['group:'.self::I]->value);
        self::assertArrayNotHasKey('group:'.self::M, $regrouped->cells);
    }

    public function testAMissingValuationMakesEveryGroupNonCalculable(): void
    {
        $regrouped = $this->regroup($this->figures([
            new AccountWeight('a1', null, null, true),
            new AccountWeight('a2', '500.50', 'EUR', true),
        ], []), ['a1' => self::B, 'a2' => self::M]);

        foreach ([self::I, self::B, self::M, self::E] as $group) {
            self::assertNull($regrouped->cells['group:'.$group]->value);
            self::assertSame('MISSING_VALUATION', $regrouped->cells['group:'.$group]->reason);
        }
        self::assertSame('MISSING_VALUATION', $regrouped->allocation[0]->reason);
        self::assertNull($regrouped->allocation[0]->value);
        self::assertNull($regrouped->allocation[0]->share);
    }

    public function testTwoAssetsMakeTheGroupsNonCalculableAndNoEligibleAccountSaysSo(): void
    {
        $mixed = $this->regroup($this->figures([
            new AccountWeight('a1', '1', 'EUR', true),
            new AccountWeight('a2', '2', 'BTC', true),
        ], []), ['a1' => self::B, 'a2' => self::M]);
        self::assertNull($mixed->cells['group:'.self::I]->value);
        self::assertSame('MIXED_ASSETS', $mixed->cells['group:'.self::I]->reason);

        $none = $this->regroup($this->figures([new AccountWeight('a1', '5', 'EUR', false)], []), ['a1' => self::B]);
        self::assertNull($none->cells['group:'.self::I]->value);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $none->cells['group:'.self::I]->reason);
    }

    public function testNearTheDecimalBoundsTheRollUpStaysExact(): void
    {
        $big = '99999999999999999999999999.999999999999999999999999';
        $regrouped = $this->regroup($this->figures([
            new AccountWeight('a1', $big, 'EUR', true),
            new AccountWeight('a2', '-'.$big, 'EUR', true),
        ], []), ['a1' => self::B, 'a2' => self::M]);

        self::assertSame($big, $regrouped->cells['group:'.self::B]->value);
        self::assertSame('-'.$big, $regrouped->cells['group:'.self::M]->value);
        // Eligible net worth is exactly zero: no share can be computed, and it is not read as zero.
        self::assertSame('0.000000000000000000000000', $regrouped->cells['group:'.self::I]->value);
        self::assertNull($regrouped->allocation[0]->share);
        self::assertSame('ZERO_ELIGIBLE_NET_WORTH', $regrouped->allocation[0]->reason);
    }

    public function testAnAccountWithNoPrimaryGroupWeighsInNoGroup(): void
    {
        $regrouped = $this->regroup($this->figures([
            new AccountWeight('a1', '10', 'EUR', true),
            new AccountWeight('a2', '5', 'EUR', true),
        ], []), ['a1' => null]);

        self::assertSame('0', $regrouped->cells['group:'.self::I]->value);
        self::assertSame('0', $regrouped->cells['group:'.self::B]->value);
    }

    public function testASnapshotWithoutWeightsCannotBeRegrouped(): void
    {
        $legacy = new MonthFigures('2026-03', 1, 'CURRENT', 0, '2026-03-31', [], [], [], null);

        $this->expectException(\LogicException::class);
        $this->regroup($legacy, []);
    }

    public function testTheTreeOfAnotherWorkspaceIsNeverRead(): void
    {
        $reader = (new FixedGroupTree())
            ->with(WorkspaceFixture::own(), $this->tree(['a1' => self::B]))
            ->with(WorkspaceFixture::other(), new CurrentGroupTree([new GroupLineage('foreign', ['foreign'])], ['a1' => 'foreign']));
        $figures = $this->figures([new AccountWeight('a1', '10', 'EUR', true)], []);

        $regrouped = (new RegroupMonthFigures($reader))->forWorkspace(WorkspaceFixture::own())($figures);

        self::assertArrayNotHasKey('group:foreign', $regrouped->cells);
        self::assertSame('10', $regrouped->cells['group:'.self::B]->value);
    }

    public function testTheTreeIsReadOnceForAllTheMonthsOfARead(): void
    {
        $reader = (new FixedGroupTree())->with(WorkspaceFixture::own(), $this->tree(['a1' => self::B]));
        $regroup = (new RegroupMonthFigures($reader))->forWorkspace(WorkspaceFixture::own());
        $figures = $this->figures([new AccountWeight('a1', '10', 'EUR', true)], []);

        $regroup($figures);
        $regroup($figures);

        self::assertSame(1, $reader->reads);
    }

    /** @param array<string, ?string> $primary */
    private function regroup(MonthFigures $figures, array $primary): MonthFigures
    {
        return $this->regroupWith($figures, $this->tree($primary));
    }

    private function regroupWith(MonthFigures $figures, CurrentGroupTree $tree): MonthFigures
    {
        $reader = (new FixedGroupTree())->with(WorkspaceFixture::own(), $tree);

        return (new RegroupMonthFigures($reader))->forWorkspace(WorkspaceFixture::own())($figures);
    }

    /** @param array<string, ?string> $primary */
    private function tree(array $primary): CurrentGroupTree
    {
        return new CurrentGroupTree([
            new GroupLineage(self::I, [self::I]),
            new GroupLineage(self::B, [self::B, self::I]),
            new GroupLineage(self::M, [self::M, self::I]),
            new GroupLineage(self::E, [self::E]),
        ], $primary);
    }

    /**
     * @param list<AccountWeight>   $weights
     * @param array<string, string> $groupCells
     */
    private function figures(array $weights, array $groupCells): MonthFigures
    {
        $cells = [
            'endNetWorth' => new MonthlyMetricView('1300.25', 'EUR', null),
            'account:a1' => new MonthlyMetricView('1000.00', 'EUR', null),
            'group:stale' => new MonthlyMetricView('1', 'EUR', null),
        ];
        foreach ($groupCells as $id => $value) {
            $cells[$id] = new MonthlyMetricView($value, 'EUR', null);
        }

        return new MonthFigures('2026-03', 1, 'CURRENT', 0, '2026-03-31', $cells, [], [new AllocationFigure('stale', '1', 'EUR', '1', null)], $weights);
    }
}

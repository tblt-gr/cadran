<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AssessPeriodClosing;
use App\Module\Accounts\Application\InvalidPeriodClosureInput;
use App\Module\Accounts\Application\PeriodClosingBlocker;
use App\Module\Accounts\Application\PeriodClosingBlockerItem;
use App\Module\Accounts\Application\PeriodClosingFacts;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosingCondition;
use App\Module\Accounts\Domain\PeriodClosingReason;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;

/** The accounts behind each failing condition, with the precise reason of each one. */
final class AssessPeriodClosingItemsTest extends TestCase
{
    private const string COURANT = '00000000-0000-7000-8000-000000000c01';
    private const string LIVRET = '00000000-0000-7000-8000-000000000c02';
    private const string JOINT = '00000000-0000-7000-8000-000000000c03';
    private const string TITRES = '00000000-0000-7000-8000-000000000c04';

    public function testEveryReasonIsReportedWithItsAccountAndDates(): void
    {
        $courant = $this->account(self::COURANT, 'Courant');
        $livret = $this->account(self::LIVRET, 'Livret A');
        $joint = $this->account(self::JOINT, 'Joint');
        $titres = $this->account(self::TITRES, 'Titres', AccountKind::PORTFOLIO, AccountValuationMode::PORTFOLIO);

        $blockers = $this->assess(
            [$courant, $livret, $joint, $titres],
            [
                $this->snapshot(self::LIVRET, '2026-10-31', ReconciliationStatus::UNRECONCILED),
                $this->snapshot(self::JOINT, '2026-10-31', ReconciliationStatus::UNRECONCILED),
                $this->snapshot(self::TITRES, '2026-10-31', ReconciliationStatus::RECONCILED, BalanceSnapshotSource::MANUAL),
            ],
            [self::JOINT => DecimalValue::fromString('12.40')],
            3,
        );

        $byCondition = $this->byCondition($blockers);
        $unreconciled = $byCondition[PeriodClosingCondition::UNRECONCILED_ACCOUNT->value];
        self::assertSame(3, $unreconciled->count);
        self::assertCount($unreconciled->count, $unreconciled->items);
        self::assertSame(
            [
                [self::COURANT, 'Courant', PeriodClosingReason::NO_BALANCE_IN_MONTH, null, null],
                [self::LIVRET, 'Livret A', PeriodClosingReason::BALANCE_NOT_RECONCILED, '2026-10-31', null],
                [self::JOINT, 'Joint', PeriodClosingReason::BALANCE_NOT_RECONCILED, '2026-10-31', null],
            ],
            array_map(self::flat(...), $unreconciled->items),
        );

        $gap = $byCondition[PeriodClosingCondition::UNEXPLAINED_DISCREPANCY->value];
        self::assertSame(1, $gap->count);
        self::assertSame([[self::JOINT, 'Joint', PeriodClosingReason::DISCREPANCY, '2026-10-31', '12.40']], array_map(self::flat(...), $gap->items));

        $valuation = $byCondition[PeriodClosingCondition::MISSING_PORTFOLIO_VALUATION->value];
        self::assertSame(1, $valuation->count);
        self::assertSame([[self::TITRES, 'Titres', PeriodClosingReason::VALUATION_NOT_CALCULATED, '2026-10-31', null]], array_map(self::flat(...), $valuation->items));

        $pending = $byCondition[PeriodClosingCondition::PENDING_TRANSACTIONS->value];
        self::assertSame(3, $pending->count);
        self::assertSame([], $pending->items);
    }

    public function testANegativeGapKeepsItsSign(): void
    {
        $blockers = $this->assess(
            [$this->account(self::JOINT, 'Joint')],
            [$this->snapshot(self::JOINT, '2026-10-31', ReconciliationStatus::UNRECONCILED)],
            [self::JOINT => DecimalValue::fromString('-10')],
        );

        $gap = $this->byCondition($blockers)[PeriodClosingCondition::UNEXPLAINED_DISCREPANCY->value];
        self::assertSame('-10', $gap->items[0]->discrepancy?->value->toString());
    }

    public function testAPortfolioWithoutAnyValuationThisMonthHasNoSnapshotDate(): void
    {
        $blockers = $this->assess(
            [$this->account(self::TITRES, 'Titres', AccountKind::PORTFOLIO, AccountValuationMode::PORTFOLIO)],
            [$this->snapshot(self::TITRES, '2026-09-30', ReconciliationStatus::RECONCILED, BalanceSnapshotSource::CALCULATED)],
            [],
        );

        $item = $blockers[0]->items[0];
        self::assertSame(PeriodClosingReason::NO_MONTH_END_VALUATION, $item->reason);
        self::assertNull($item->snapshotAsOf);
    }

    public function testAGapOfZeroOrAnUncalculableGapIsNotListed(): void
    {
        $blockers = $this->assess(
            [$this->account(self::JOINT, 'Joint')],
            [$this->snapshot(self::JOINT, '2026-10-31', ReconciliationStatus::UNRECONCILED)],
            [],
        );

        self::assertSame([PeriodClosingCondition::UNRECONCILED_ACCOUNT], array_map(static fn (PeriodClosingBlocker $blocker): PeriodClosingCondition => $blocker->condition, $blockers));
    }

    public function testNothingBlocksWhenEveryAccountIsReconciled(): void
    {
        $blockers = $this->assess(
            [$this->account(self::JOINT, 'Joint')],
            [$this->snapshot(self::JOINT, '2026-10-31', ReconciliationStatus::RECONCILED)],
            [],
        );

        self::assertSame([], $blockers);
    }

    public function testVeryLargeGapsStayExact(): void
    {
        $large = '12345678901234567890123456.123456789012345678901234';
        $blockers = $this->assess(
            [$this->account(self::JOINT, 'Joint')],
            [$this->snapshot(self::JOINT, '2026-10-31', ReconciliationStatus::UNRECONCILED)],
            [self::JOINT => DecimalValue::fromString($large)],
        );

        self::assertSame($large, $this->byCondition($blockers)[PeriodClosingCondition::UNEXPLAINED_DISCREPANCY->value]->items[0]->discrepancy?->value->toString());
    }

    public function testItemsFollowTheOverviewOrderGroupsFirstThenUngrouped(): void
    {
        $ungrouped = $this->account(self::COURANT, 'Courant');
        $inGroupB = $this->account(self::JOINT, 'Joint', primaryGroupId: AccountGroupFixture::OTHER_ID);
        $inGroupA = $this->account(self::LIVRET, 'Livret A', primaryGroupId: AccountGroupFixture::ID);

        $blockers = $this->assess(
            [$ungrouped, $inGroupB, $inGroupA],
            [],
            [],
            groups: [
                AccountGroupFixture::group(id: AccountGroupFixture::ID, sortOrder: 2),
                AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, label: 'Courants', sortOrder: 1),
            ],
        );

        self::assertSame([self::JOINT, self::LIVRET, self::COURANT], array_map(static fn ($item): string => $item->accountId, $blockers[0]->items));
    }

    public function testAWorkspaceWithManyGroupsAndNothingBlockingNeverReadsTheGroups(): void
    {
        $groups = new InMemoryAccountGroupRepository(...$this->manyGroups());

        $blockers = $this->assess(
            [$this->account(self::JOINT, 'Joint')],
            [$this->snapshot(self::JOINT, '2026-10-31', ReconciliationStatus::RECONCILED)],
            [],
            groupRepository: $groups,
        );

        self::assertSame([], $blockers);
        self::assertSame(0, $groups->listCalls);
    }

    public function testTooManyGroupsFallBackToTheSavedOrderInsteadOfRefusing(): void
    {
        $blockers = $this->assess(
            [$this->account(self::COURANT, 'Courant'), $this->account(self::JOINT, 'Joint')],
            [],
            [],
            groupRepository: new InMemoryAccountGroupRepository(...$this->manyGroups()),
        );

        self::assertSame([self::COURANT, self::JOINT], array_map(static fn ($item): string => $item->accountId, $blockers[0]->items));
    }

    public function testAnotherWorkspacesAccountsAreNeverListed(): void
    {
        $foreign = AccountFixture::account(id: self::LIVRET, workspace: '00000000-0000-7000-8000-0000000000ff', label: 'Etranger');
        $mine = $this->account(self::COURANT, 'Courant');

        $blockers = $this->assess([$mine, $foreign], [], []);

        self::assertSame([self::COURANT], array_map(static fn ($item): string => $item->accountId, $blockers[0]->items));
    }

    public function testMoreThanFiveHundredAccountsAreRefused(): void
    {
        $accounts = [];
        for ($i = 0; $i <= AssessPeriodClosing::MAX_ACCOUNTS; ++$i) {
            $accounts[] = $this->account(sprintf('00000000-0000-7000-8000-%012d', $i + 1), 'A'.$i);
        }

        $this->expectException(InvalidPeriodClosureInput::class);
        $this->assess($accounts, [], []);
    }

    /** @return list<AccountGroup> */
    private function manyGroups(): array
    {
        $groups = [];
        for ($i = 1; $i <= ReadNetWorth::MAX_GROUPS + 1; ++$i) {
            $groups[] = AccountGroupFixture::group(id: sprintf('00000000-0000-7000-8000-%012d', 5000 + $i), label: 'G'.$i, sortOrder: $i);
        }

        return $groups;
    }

    /**
     * @param list<Account>                $accounts
     * @param list<AccountBalanceSnapshot> $snapshots
     * @param array<string, DecimalValue>  $gaps
     * @param list<AccountGroup>           $groups
     *
     * @return list<PeriodClosingBlocker>
     */
    private function assess(array $accounts, array $snapshots, array $gaps, int $pending = 0, array $groups = [], ?InMemoryAccountGroupRepository $groupRepository = null): array
    {
        $facts = new class($gaps, $pending) implements PeriodClosingFacts {
            /** @param array<string, DecimalValue> $gaps */
            public function __construct(private readonly array $gaps, private readonly int $pending)
            {
            }

            public function pendingTransactionCount(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int
            {
                return $this->pending;
            }

            public function unexplainedDiscrepancies(WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): array
            {
                return $this->gaps;
            }
        };
        $assess = new AssessPeriodClosing(
            new InMemoryAccountRepository(...$accounts),
            new InMemoryAccountBalanceSnapshotRepository(...$snapshots),
            $groupRepository ?? new InMemoryAccountGroupRepository(...$groups),
            $facts,
            new FixedWorkspaceTimezoneReader(),
        );

        return $assess(WorkspaceScope::fromString(AccountFixture::WORKSPACE), CalendarMonth::containing(new \DateTimeImmutable('2026-10-15')));
    }

    /**
     * @param list<PeriodClosingBlocker> $blockers
     *
     * @return array<string, PeriodClosingBlocker>
     */
    private function byCondition(array $blockers): array
    {
        $indexed = [];
        foreach ($blockers as $blocker) {
            $indexed[$blocker->condition->value] = $blocker;
        }

        return $indexed;
    }

    /** @return array{string, string, PeriodClosingReason, ?string, ?string} */
    private static function flat(PeriodClosingBlockerItem $item): array
    {
        return [$item->accountId, $item->label, $item->reason, $item->snapshotAsOf?->format('Y-m-d'), $item->discrepancy?->value->toString()];
    }

    private function account(string $id, string $label, AccountKind $kind = AccountKind::CURRENT, AccountValuationMode $mode = AccountValuationMode::TRANSACTIONS, ?string $primaryGroupId = null): Account
    {
        return AccountFixture::account(productCode: null, kind: $kind, id: $id, valuationMode: $mode, label: $label, primaryGroupId: $primaryGroupId);
    }

    private function snapshot(string $accountId, string $asOf, ReconciliationStatus $status, BalanceSnapshotSource $source = BalanceSnapshotSource::MANUAL): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-'.substr(md5($accountId.$asOf), 0, 12),
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: $accountId,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString('112.40'), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: $status,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-11-03T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}

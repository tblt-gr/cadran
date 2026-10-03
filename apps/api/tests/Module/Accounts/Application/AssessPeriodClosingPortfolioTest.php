<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AssessPeriodClosing;
use App\Module\Accounts\Application\PeriodClosingBlocker;
use App\Module\Accounts\Application\PeriodClosingFacts;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSnapshotSource;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosingCondition;
use App\Module\Accounts\Domain\ReconciliationStatus;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountBalanceSnapshotRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use PHPUnit\Framework\TestCase;

/** A portfolio-mode account is reconciled for a month through its valuation, never through manual balances. */
final class AssessPeriodClosingPortfolioTest extends TestCase
{
    public function testAValuationInTheMonthReconcilesAPortfolioAccountAndSkipsTheDiscrepancyComparison(): void
    {
        $facts = new class implements PeriodClosingFacts {
            public int $compared = 0;

            public function pendingTransactionCount(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int
            {
                return 0;
            }

            public function unexplainedDiscrepancyCount(WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): int
            {
                $this->compared = count($closings);

                return $this->compared;
            }
        };

        $blockers = $this->assess($facts, [$this->snapshot(BalanceSnapshotSource::CALCULATED, '2026-08-31', ReconciliationStatus::RECONCILED)]);

        self::assertSame([], $blockers);
        self::assertSame(0, $facts->compared);
    }

    public function testNoValuationInTheMonthBlocksAPortfolioAccount(): void
    {
        $blockers = $this->assess($this->quietFacts(), []);

        self::assertSame([PeriodClosingCondition::UNRECONCILED_ACCOUNT], array_map(static fn (PeriodClosingBlocker $blocker): PeriodClosingCondition => $blocker->condition, $blockers));
    }

    public function testAManualHistoricalBalanceInTheMonthDoesNotReconcileAPortfolioAccount(): void
    {
        $blockers = $this->assess($this->quietFacts(), [$this->snapshot(BalanceSnapshotSource::MANUAL, '2026-08-31', ReconciliationStatus::RECONCILED)]);

        self::assertSame([PeriodClosingCondition::UNRECONCILED_ACCOUNT], array_map(static fn (PeriodClosingBlocker $blocker): PeriodClosingCondition => $blocker->condition, $blockers));
    }

    public function testAValuationOfAnEarlierMonthDoesNotReconcileTheMonth(): void
    {
        $blockers = $this->assess($this->quietFacts(), [$this->snapshot(BalanceSnapshotSource::CALCULATED, '2026-07-31', ReconciliationStatus::RECONCILED)]);

        self::assertCount(1, $blockers);
    }

    /**
     * @param list<AccountBalanceSnapshot> $snapshots
     *
     * @return list<PeriodClosingBlocker>
     */
    private function assess(PeriodClosingFacts $facts, array $snapshots): array
    {
        $account = AccountFixture::account(productCode: null, kind: AccountKind::PORTFOLIO, valuationMode: AccountValuationMode::PORTFOLIO);
        $assess = new AssessPeriodClosing(
            new InMemoryAccountRepository($account),
            new InMemoryAccountBalanceSnapshotRepository(...$snapshots),
            $facts,
            new FixedWorkspaceTimezoneReader(),
        );

        return $assess(WorkspaceScope::fromString(AccountFixture::WORKSPACE), CalendarMonth::containing(new \DateTimeImmutable('2026-08-15')));
    }

    private function quietFacts(): PeriodClosingFacts
    {
        return new class implements PeriodClosingFacts {
            public function pendingTransactionCount(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int
            {
                return 0;
            }

            public function unexplainedDiscrepancyCount(WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): int
            {
                return 0;
            }
        };
    }

    private function snapshot(BalanceSnapshotSource $source, string $asOf, ReconciliationStatus $status): AccountBalanceSnapshot
    {
        return new AccountBalanceSnapshot(
            id: '00000000-0000-7000-8000-0000000000b1',
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            asOf: new \DateTimeImmutable($asOf, new \DateTimeZone('UTC')),
            amount: new AssetAmount(DecimalValue::fromString('3664.70'), AssetCode::fromString('EUR')),
            source: $source,
            reconciliationStatus: $status,
            comment: null,
            version: 1,
            recordedAt: new \DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            recordedBy: '00000000-0000-7000-8000-000000000001',
        );
    }
}

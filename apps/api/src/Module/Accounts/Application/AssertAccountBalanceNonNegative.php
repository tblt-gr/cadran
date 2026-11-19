<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountBalanceFloor;
use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\BalanceSeries;
use App\Module\Accounts\Domain\DayBalance;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The single guard of every interactive write that can lower the balance of a
 * current, savings or cash account. It runs inside the caller's transaction,
 * after the period guard, and locks the guarded accounts in ascending id order so
 * two concurrent writes on one account serialise and the second sees the first.
 *
 * Only an interactive write is judged: the console (demo seed, migration, jobs) records a planned or
 * past fact. An account with no declared balance before the window, or one holding a
 * movement in another asset, has no calculable balance: the write is accepted,
 * because a missing value is never read as zero.
 */
final readonly class AssertAccountBalanceNonNegative
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountBalanceSnapshotRepository $snapshots,
        private BalanceMovementReader $movements,
        private InteractiveWriteContext $writeContext,
    ) {
    }

    /**
     * @param list<AccountBalanceDelta> $deltas signed changes of the write, removals negated
     *
     * @throws InsufficientAccountBalance
     */
    public function __invoke(WorkspaceScope $workspace, array $deltas): void
    {
        if (!$this->writeContext->isInteractive()) {
            return;
        }
        $byAccount = [];
        foreach ($deltas as $delta) {
            $byAccount[$delta->accountId][] = $delta;
        }
        ksort($byAccount);

        foreach ($byAccount as $accountId => $accountDeltas) {
            $account = $this->accounts->findForUpdate($workspace, (string) $accountId);
            if (null === $account || !self::isGuarded($account)) {
                continue;
            }
            $from = $accountDeltas[0]->day;
            $series = [];
            foreach ($accountDeltas as $delta) {
                $from = min($from, $delta->day);
                $series[] = new DayBalance($delta->day, $delta->amount);
            }
            $field = $accountDeltas[0]->field;
            foreach ($accountDeltas as $delta) {
                if (AccountBalanceDelta::FIELD_SOURCE_AMOUNT === $delta->field) {
                    $field = $delta->field;
                }
            }
            $this->check($workspace, $account, $from, $series, null, null, $field);
        }
    }

    /**
     * A declared balance replaces the snapshot of the same day or adds one. It
     * is refused when it is itself negative, or when re-anchoring leaves a later
     * day-end balance negative.
     *
     * @throws InsufficientAccountBalance
     */
    public function assertDeclared(
        WorkspaceScope $workspace,
        Account $account,
        \DateTimeImmutable $asOf,
        DecimalValue $amount,
        ?string $replacedSnapshotId = null,
    ): void {
        if (!$this->writeContext->isInteractive() || !self::isGuarded($account)) {
            return;
        }
        if ($amount->isNegative()) {
            throw new InsufficientAccountBalance($account->id, AccountBalanceDelta::FIELD_AMOUNT, $asOf);
        }
        $this->check($workspace, $account, $asOf, [], $replacedSnapshotId, new DayBalance($asOf, $amount), AccountBalanceDelta::FIELD_AMOUNT);
    }

    public static function isGuarded(Account $account): bool
    {
        return null === $account->archivedAt
            && AccountValuationMode::TRANSACTIONS === $account->valuationMode
            && in_array($account->kind, [AccountKind::CURRENT, AccountKind::SAVINGS, AccountKind::CASH], true);
    }

    /** @param list<DayBalance> $deltas */
    private function check(
        WorkspaceScope $workspace,
        Account $account,
        \DateTimeImmutable $from,
        array $deltas,
        ?string $replacedSnapshotId,
        ?DayBalance $declared,
        string $field,
    ): void {
        $anchorsBefore = [];
        $anchorsAfter = [];
        foreach ($this->snapshots->findForAccount($workspace, $account->id)->snapshots as $snapshot) {
            if (!$snapshot->isActive()) {
                continue;
            }
            $anchor = new DayBalance($snapshot->asOf, $snapshot->amount->value);
            $anchorsBefore[] = $anchor;
            if ($snapshot->id === $replacedSnapshotId) {
                $from = min($from, $snapshot->asOf);
                continue;
            }
            $anchorsAfter[] = $anchor;
        }
        if (null !== $declared) {
            $anchorsAfter[] = $declared;
        }
        $byDay = static fn (DayBalance $a, DayBalance $b): int => $a->day <=> $b->day;
        usort($anchorsBefore, $byDay);
        usort($anchorsAfter, $byDay);
        if ([] === $anchorsBefore && [] === $anchorsAfter) {
            return;
        }

        $earliest = ($anchorsBefore[0] ?? $anchorsAfter[0])->day;
        if ([] !== $anchorsBefore && [] !== $anchorsAfter) {
            $earliest = min($anchorsBefore[0]->day, $anchorsAfter[0]->day);
        }
        $movements = $this->movements->dailyNet($workspace, $account->id, $account->assetCode, $earliest);
        if ($movements->mixedAssets) {
            return;
        }

        $before = BalanceSeries::compute($anchorsBefore, $movements->days, [], $from);
        $after = BalanceSeries::compute($anchorsAfter, $movements->days, $deltas, $from);
        $breach = AccountBalanceFloor::breach($before, $after);
        if (null !== $breach) {
            throw new InsufficientAccountBalance($account->id, $field, $breach);
        }
    }
}

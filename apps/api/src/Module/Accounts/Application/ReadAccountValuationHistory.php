<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshotRepository;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** Bounded daily resolution preserves the shared strategy, then emits lossless steps. */
final readonly class ReadAccountValuationHistory
{
    private const int MAX_UNBOUNDED_DAYS = 3660;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private ResolveAccountValuations $valuations,
        private WorkspaceCalendar $calendar,
        private AccountBalanceSnapshotRepository $snapshots,
    ) {
    }

    public function __invoke(string $accountId, ?string $from, string $to): AccountValuationHistoryView
    {
        $workspace = $this->caller->resolve();
        $account = $this->accounts->find($workspace, $accountId);
        if (null === $account) {
            throw new AccountNotFound('No account carries this identifier in this workspace.');
        }
        try {
            $end = BusinessDay::fromIsoDate($to)->date;
            $start = null === $from ? $end : BusinessDay::fromIsoDate($from)->date;
        } catch (\Throwable $exception) {
            throw new InvalidAccountBalanceInput('History requires ISO calendar days.', previous: $exception);
        }
        $truncated = false;
        if (null === $from) {
            if ($end > $this->calendar->today()) {
                throw new InvalidAccountBalanceInput('History must end no later than today.');
            }
            // Only a snapshot anchors a balance: earlier days, even with movements, would all be null NO_ANCHOR points.
            $earliest = $this->snapshots->earliestActiveAsOf($workspace, $account->id);
            if (null !== $earliest && $earliest < $end) {
                $floor = $end->modify('-'.self::MAX_UNBOUNDED_DAYS.' days');
                $truncated = $earliest < $floor;
                $start = $truncated ? $floor : $earliest;
            }
        } elseif ($start > $end || $end > $this->calendar->today() || (int) $start->diff($end)->format('%a') > 366) {
            throw new InvalidAccountBalanceInput('History accepts at most 366 elapsed days, ending no later than today.');
        }
        $days = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }
        $points = [];
        $previous = null;
        $hasHistory = false;
        // At most 62 batches, independent of transaction volume; never a query per movement.
        foreach (array_chunk($days, 60) as $batch) {
            $resolved = $this->valuations->forAccounts($workspace, [$account], $batch);
            foreach ($batch as $day) {
                $valuation = $resolved[$day->format('Y-m-d')][$account->id];
                $hasHistory = $hasHistory || null !== $valuation->snapshot;
                $key = self::changeKey($valuation);
                if ($day == $start || $day == $end || $key !== $previous) {
                    $points[] = new AccountValuationHistoryPoint(
                        $day->format('Y-m-d'),
                        $valuation->amount?->value->toString(),
                        $valuation->amount?->asset->toString(),
                        $valuation->quality->value,
                        $valuation->source?->value,
                        $valuation->reason ?? (null === $valuation->snapshot ? 'NO_ANCHOR' : null),
                        null === $valuation->amount ? null : $valuation->asOf->format('Y-m-d'),
                        $valuation->ageDays,
                        $valuation->basis?->value,
                        null === $valuation->derivation ? null : new ValuationDerivationView(
                            $valuation->derivation->anchorAsOf->format('Y-m-d'),
                            $valuation->derivation->anchorAgeDays,
                            $valuation->derivation->movementCount,
                            $valuation->derivation->pendingCount,
                        ),
                    );
                }
                $previous = $key;
            }
        }

        return new AccountValuationHistoryView($account->id, $start->format('Y-m-d'), $to, $hasHistory, $truncated, $points);
    }

    private static function changeKey(AccountValuation $valuation): string
    {
        // Ages and requested-day provenance advance during a held step. Booked count keeps
        // same-amount movements visible; pending movements never create a balance step.
        return json_encode([
            $valuation->amount?->value->toString(),
            $valuation->amount?->asset->toString(),
            $valuation->quality->value,
            $valuation->source?->value,
            $valuation->snapshot?->id,
            $valuation->snapshot?->version,
            $valuation->reason,
            $valuation->basis?->value,
            $valuation->derivation?->movementCount,
        ], JSON_THROW_ON_ERROR);
    }
}

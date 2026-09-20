<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The single guard of every mutating use case that writes a dated movement.
 *
 * A write that moves a date passes both the day it leaves and the day it
 * enters: checking only the row's current month would let an edit smuggle a
 * movement out of a closed month into an open one, or the reverse. It must run
 * inside the caller's own transaction, because it takes a transaction-scoped
 * lock that serialises it against a concurrent closing.
 */
final readonly class AssertPeriodOpen
{
    public function __construct(private PeriodClosureRepository $closures)
    {
    }

    /** @throws PeriodClosed */
    public function __invoke(WorkspaceScope $workspace, \DateTimeInterface ...$days): void
    {
        if ([] === $days) {
            return;
        }
        $months = [];
        foreach ($days as $day) {
            $month = CalendarMonth::containing($day);
            $months[$month->key()] = $month;
        }

        $this->closures->lockShared($workspace);
        if ([] !== $this->closures->closedAmong($workspace, array_values($months))) {
            throw new PeriodClosed();
        }
    }

    /**
     * For a stored write whose days were never recorded: with no way to know
     * which month it touched, any active closure refuses it.
     *
     * @throws PeriodClosed
     */
    public function assertNoActiveClosure(WorkspaceScope $workspace): void
    {
        $this->closures->lockShared($workspace);
        if ($this->closures->hasActive($workspace)) {
            throw new PeriodClosed();
        }
    }

    /**
     * Refuses only lifecycle edits that change whether an account belongs to
     * an active closed month. The shared lock keeps the decision atomic with
     * the account write and a concurrent close/reopen.
     *
     * @throws PeriodClosed
     */
    public function assertAccountLifecycleUnchangedForClosures(
        WorkspaceScope $workspace,
        ?\DateTimeInterface $oldOpenedOn,
        ?\DateTimeInterface $oldClosedOn,
        \DateTimeInterface $newOpenedOn,
        ?\DateTimeInterface $newClosedOn,
    ): void {
        $from = null === $oldOpenedOn || $newOpenedOn < $oldOpenedOn ? $newOpenedOn : $oldOpenedOn;
        $to = null === $oldClosedOn || null === $newClosedOn
            ? null
            : ($newClosedOn > $oldClosedOn ? $newClosedOn : $oldClosedOn);

        $this->closures->lockShared($workspace);
        $closedMonths = $this->closures->activeBetween(
            $workspace,
            CalendarMonth::containing($from),
            null === $to ? null : CalendarMonth::containing($to),
        );
        foreach ($closedMonths as $month) {
            $wasIncluded = null !== $oldOpenedOn && self::overlaps($oldOpenedOn, $oldClosedOn, $month);
            if ($wasIncluded !== self::overlaps($newOpenedOn, $newClosedOn, $month)) {
                throw new PeriodClosed();
            }
        }
    }

    /**
     * Reads without locking, for a screen that surfaces what a closure keeps
     * from being written.
     *
     * @param list<\DateTimeInterface> $days
     *
     * @return array<string, true> the `YYYY-MM` keys of the closed months among these days
     */
    public function closedMonthKeys(WorkspaceScope $workspace, array $days): array
    {
        $months = [];
        foreach ($days as $day) {
            $month = CalendarMonth::containing($day);
            $months[$month->key()] = $month;
        }
        $closed = [];
        foreach ($this->closures->closedAmong($workspace, array_values($months)) as $month) {
            $closed[$month->key()] = true;
        }

        return $closed;
    }

    private static function overlaps(\DateTimeInterface $openedOn, ?\DateTimeInterface $closedOn, CalendarMonth $month): bool
    {
        $opened = $openedOn->format('Y-m-d');
        $closed = $closedOn?->format('Y-m-d');

        return $opened <= $month->lastDay()->format('Y-m-d')
            && (null === $closed || $closed >= $month->firstDay()->format('Y-m-d'));
    }
}

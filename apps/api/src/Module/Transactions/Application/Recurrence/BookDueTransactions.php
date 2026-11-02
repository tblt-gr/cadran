<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Transactions\Application\TransactionAuditEvents;
use App\Module\Transactions\Application\TransactionAuditFingerprint;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Books, on their date, the planned rows of the workspace: the pending rows
 * carrying the booking marker whose day has come, whether a recurrence created
 * them or the owner entered them ahead of time.
 *
 * Only the marker selects a row. A pending row imported or delivered by a
 * provider, or held for reconciliation review, never carries it and so is never
 * booked here. Each row is locked and written under its expected version, so
 * two racing runs book it once.
 */
final readonly class BookDueTransactions
{
    private const int MAX_PER_RUN = 1000;
    private const int MAX_PAGES = 50;

    public function __construct(
        private TransactionRepository $transactions,
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private AssertPeriodOpen $assertPeriodOpen,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
        private int $maxPerRun = self::MAX_PER_RUN,
    ) {
    }

    /** Runs inside the caller's database transaction. */
    public function __invoke(WorkspaceContext $context, \DateTimeImmutable $today, MaterializationTally $tally): void
    {
        $workspace = $context->workspace;
        // Rows a run could not book (closed period, concurrent change) are excluded from the next
        // page, so a backlog of unbookable old rows never starves a newer bookable one.
        $unbooked = [];
        $done = 0;
        for ($page = 0; $page < self::MAX_PAGES && $done < $this->maxPerRun; ++$page) {
            $ids = $this->transactions->listAutoBookDueIds($workspace, $today, $this->maxPerRun, $unbooked);
            foreach ($ids as $id) {
                if ($this->book($context, $today, $id, $tally)) {
                    ++$done;
                } else {
                    $unbooked[] = $id;
                }
            }
            if (count($ids) < $this->maxPerRun) {
                return;
            }
        }
    }

    private function book(WorkspaceContext $context, \DateTimeImmutable $today, string $id, MaterializationTally $tally): bool
    {
        $workspace = $context->workspace;
        $transaction = $this->transactions->findForUpdate($workspace, $id);
        if (null === $transaction || TransactionState::PENDING !== $transaction->state
            || !$transaction->autoBook || $transaction->bookedOn > $today) {
            return false;
        }
        try {
            ($this->assertPeriodOpen)($workspace, $transaction->bookedOn);
            $booked = $transaction->edit(
                $transaction->amount, $transaction->nature, TransactionState::BOOKED, $transaction->bookedOn,
                $transaction->valueOn, $transaction->authorizedOn, $transaction->rawLabel, $transaction->counterparty,
                $transaction->note, $transaction->paymentMethod, $transaction->mcc, $transaction->maskedCard,
                $transaction->bankReference, $transaction->splits, $this->calendar->now(), $context->actorId,
            );
        } catch (PeriodClosed) {
            ++$tally->skippedClosedPeriod;

            return false;
        } catch (InvalidTransaction) {
            ++$tally->skippedRefused;

            return false;
        }
        if (!$this->transactions->update($booked, $transaction->version)) {
            return false;
        }
        ($this->audit)(new AuditEventRecord(
            $workspace, $context->actorId, TransactionAuditEvents::UPDATED, TransactionAuditEvents::ENTITY, $booked->id,
            AuditDiff::change(TransactionAuditFingerprint::of($transaction), TransactionAuditFingerprint::changed($transaction, $booked)),
        ));
        $occurrence = $this->occurrences->findByGeneratedTransaction($workspace, $booked->id);
        if (null !== $occurrence) {
            // The instalment is settled by the row generated for it; false means a movement already did.
            $this->occurrences->claim($workspace, $occurrence->id, $booked->id, $this->calendar->now());
        }
        ++$tally->booked;

        return true;
    }
}

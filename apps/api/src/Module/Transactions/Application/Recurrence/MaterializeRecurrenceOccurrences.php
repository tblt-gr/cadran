<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\TransactionAuditEvents;
use App\Module\Transactions\Application\TransactionAuditFingerprint;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Creates, for every live auto-create recurrence, the transactions of the
 * current calendar month only, then books the ones whose day has come.
 *
 * Every step is expressed as "what is still missing", so repeating a run, or
 * racing two of them, produces nothing twice: the recurrence row is locked
 * while it is processed, and a unique link plus the transaction `source_ref`
 * (the instalment identifier) back that up in the database. A generated row the
 * owner voided or rejected keeps its link, so it is never created again.
 */
final readonly class MaterializeRecurrenceOccurrences
{
    private const int MAX_RECURRENCES = 1000;
    private const int MAX_INSTALMENTS = 500;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private TransactionRepository $transactions,
        private OccurrenceGenerator $generator,
        private CreateTransaction $createTransaction,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $boundary,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(): MaterializeRecurrencesResult
    {
        $context = $this->caller->resolveContext();
        $today = $this->calendar->today();
        $total = new MaterializationTally();
        $examined = 0;
        foreach ($this->recurrences->activeAutoCreating($context->workspace, self::MAX_RECURRENCES) as $listed) {
            ++$examined;
            $total->add($this->boundary->transactional(function () use ($context, $listed, $today): MaterializationTally {
                $current = $this->recurrences->findForUpdate($context->workspace, $listed->id);
                if (null === $current || null !== $current->archivedAt || !$current->autoCreate) {
                    return new MaterializationTally();
                }

                return $this->forRecurrence($context, $current, $today);
            }));
        }

        return new MaterializeRecurrencesResult(
            $examined, $total->created, $total->booked, $total->skippedClosedPeriod, $total->skippedRefused,
            self::monthStart($today)->format('Y-m-d'), self::monthEnd($today)->format('Y-m-d'),
        );
    }

    /**
     * One locked recurrence, inside its caller's transaction: also the entry
     * point of a save that turns the setting on.
     */
    public function forRecurrence(WorkspaceContext $context, TransactionRecurrence $recurrence, \DateTimeImmutable $today): MaterializationTally
    {
        $tally = new MaterializationTally();
        $this->generator->extendToHorizon($recurrence, $today);
        $pending = $this->occurrences->listUngenerated(
            $recurrence->workspace, $recurrence->id, self::monthStart($today), self::monthEnd($today), self::MAX_INSTALMENTS,
        );
        foreach ($pending as $occurrence) {
            $this->create($context, $recurrence, $occurrence, $today, $tally);
        }
        $this->bookDue($context, $recurrence, $today, $tally);
        $this->recurrences->advanceNextExpectedOn(
            $recurrence->workspace, $recurrence->id, $this->generator->pointer($recurrence, $today),
        );
        if ($tally->changedAnything()) {
            ($this->audit)(new AuditEventRecord(
                $recurrence->workspace, $context->actorId, RecurrenceAuditEvents::MATERIALIZED,
                RecurrenceAuditEvents::ENTITY, $recurrence->id,
                AuditDiff::creation(['transactionsCreated' => $tally->created, 'transactionsBooked' => $tally->booked]),
            ));
        }

        return $tally;
    }

    private function create(
        WorkspaceContext $context,
        TransactionRecurrence $recurrence,
        TransactionRecurrenceOccurrence $occurrence,
        \DateTimeImmutable $today,
        MaterializationTally $tally,
    ): void {
        $booked = $occurrence->expectedOn <= $today;
        try {
            $view = ($this->createTransaction)(new CreateTransactionInput(
                accountId: $recurrence->accountId,
                amount: ['value' => $occurrence->expectedAmount->value->toString(), 'assetCode' => $occurrence->expectedAmount->asset->toString()],
                nature: $occurrence->expectedAmount->value->isNegative() ? 'EXPENSE' : 'INCOME',
                state: $booked ? TransactionState::BOOKED->value : TransactionState::PENDING->value,
                bookedOn: $occurrence->expectedOn->format('Y-m-d'),
                valueOn: null, authorizedOn: null,
                rawLabel: $recurrence->label, counterparty: $recurrence->counterparty, note: $recurrence->note,
                paymentMethod: $recurrence->paymentMethod?->value, mcc: null, maskedCard: null, bankReference: null,
                categoryId: $recurrence->categoryId, splits: null,
                source: TransactionSource::RECURRENCE->value, sourceRef: $occurrence->id,
                reconcile: false, automation: true, matchRecurrence: false,
            ));
        } catch (PeriodClosed) {
            ++$tally->skippedClosedPeriod;

            return;
        } catch (TransactionConflict) {
            $this->adoptExisting($recurrence, $occurrence, $tally);

            return;
        } catch (InvalidTransactionInput|TransactionNotFound) {
            ++$tally->skippedRefused;

            return;
        }
        if (!$this->occurrences->recordGenerated($recurrence->workspace, $occurrence->id, $view->id, $view->version)) {
            throw new \LogicException('A locked instalment must accept the link of the transaction just created for it.');
        }
        ++$tally->created;
        if ($booked) {
            // Created already booked: the instalment is settled by it, exactly
            // as a due pending row is when it gets booked below.
            $this->occurrences->claim($recurrence->workspace, $occurrence->id, $view->id, $this->calendar->now());
        }
    }

    /**
     * A duplicate `source_ref` means this very instalment already has its
     * transaction: link it when the instalment still lacks the link, count
     * nothing. Any other conflict is a refusal.
     */
    private function adoptExisting(
        TransactionRecurrence $recurrence,
        TransactionRecurrenceOccurrence $occurrence,
        MaterializationTally $tally,
    ): void {
        $existing = $this->transactions->findBySourceRef($recurrence->workspace, $recurrence->accountId, $occurrence->id, false);
        if (null === $existing || $existing->state->isTerminal()) {
            ++$tally->skippedRefused;

            return;
        }
        $this->occurrences->recordGenerated($recurrence->workspace, $occurrence->id, $existing->id, $existing->version);
    }

    private function bookDue(WorkspaceContext $context, TransactionRecurrence $recurrence, \DateTimeImmutable $today, MaterializationTally $tally): void
    {
        $workspace = $recurrence->workspace;
        foreach ($this->occurrences->listGeneratedPendingDue($workspace, $recurrence->id, $today, self::MAX_INSTALMENTS) as $occurrence) {
            $transaction = null === $occurrence->generatedTransactionId
                ? null
                : $this->transactions->findForUpdate($workspace, $occurrence->generatedTransactionId);
            if (null === $transaction || TransactionState::PENDING !== $transaction->state) {
                continue;
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

                continue;
            } catch (InvalidTransaction) {
                ++$tally->skippedRefused;

                continue;
            }
            if (!$this->transactions->update($booked, $transaction->version)) {
                continue;
            }
            ($this->audit)(new AuditEventRecord(
                $workspace, $context->actorId, TransactionAuditEvents::UPDATED, TransactionAuditEvents::ENTITY, $booked->id,
                AuditDiff::change(TransactionAuditFingerprint::of($transaction), TransactionAuditFingerprint::changed($transaction, $booked)),
            ));
            $this->occurrences->claim($workspace, $occurrence->id, $booked->id, $this->calendar->now());
            ++$tally->booked;
        }
    }

    private static function monthStart(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->modify('first day of this month');
    }

    private static function monthEnd(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->modify('last day of this month');
    }
}

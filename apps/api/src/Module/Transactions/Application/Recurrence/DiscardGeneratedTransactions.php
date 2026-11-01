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
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Takes back what a recurrence generated and nobody has touched since.
 *
 * A generated transaction is untouched while it is still pending and its
 * version is the one the instalment recorded at creation: any edit by the
 * owner bumps the version, which is how a hand-made change is told apart from
 * the system's own row without a flag. Booked rows, past instalments and
 * touched rows are never rewritten.
 */
final readonly class DiscardGeneratedTransactions
{
    private const int MAX_INSTALMENTS = 500;

    public function __construct(
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private TransactionRepository $transactions,
        private AssertPeriodOpen $assertPeriodOpen,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** Rejects the untouched pending rows dated from `$from` and frees their instalments. */
    public function __invoke(WorkspaceContext $context, TransactionRecurrence $recurrence, \DateTimeImmutable $from): void
    {
        $workspace = $context->workspace;
        foreach ($this->occurrences->listGeneratedFrom($workspace, $recurrence->id, $from, self::MAX_INSTALMENTS) as $occurrence) {
            if (null === $occurrence->generatedTransactionId) {
                continue;
            }
            $transaction = $this->transactions->findForUpdate($workspace, $occurrence->generatedTransactionId);
            if (null === $transaction || TransactionState::PENDING !== $transaction->state
                || $transaction->version !== $occurrence->generatedVersion) {
                continue;
            }
            try {
                ($this->assertPeriodOpen)($workspace, $transaction->bookedOn);
                $rejected = $transaction->edit(
                    $transaction->amount, $transaction->nature, TransactionState::REJECTED, $transaction->bookedOn,
                    $transaction->valueOn, $transaction->authorizedOn, $transaction->rawLabel, $transaction->counterparty,
                    $transaction->note, $transaction->paymentMethod, $transaction->mcc, $transaction->maskedCard,
                    $transaction->bankReference, $transaction->splits, $this->calendar->now(), $context->actorId,
                );
            } catch (PeriodClosed|InvalidTransaction) {
                continue;
            }
            if (!$this->transactions->update($rejected, $transaction->version)) {
                continue;
            }
            $this->occurrences->clearGenerated($workspace, $occurrence->id);
            ($this->audit)(new AuditEventRecord(
                $workspace, $context->actorId, TransactionAuditEvents::UPDATED, TransactionAuditEvents::ENTITY, $rejected->id,
                AuditDiff::change(TransactionAuditFingerprint::of($transaction), TransactionAuditFingerprint::changed($transaction, $rejected)),
            ));
        }
    }
}

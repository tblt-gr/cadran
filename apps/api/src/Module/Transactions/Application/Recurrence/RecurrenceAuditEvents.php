<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

final readonly class RecurrenceAuditEvents
{
    public const string ENTITY = 'transaction_recurrence';
    public const string CONFIRMED = 'transaction_recurrence.confirmed';
    public const string UPDATED = 'transaction_recurrence.updated';
    public const string ARCHIVED = 'transaction_recurrence.archived';
    public const string CANDIDATE_ENTITY = 'transaction_recurrence_candidate';
    public const string CANDIDATE_DISMISSED = 'transaction_recurrence_candidate.dismissed';
    public const string CANDIDATE_RESTORED = 'transaction_recurrence_candidate.restored';
}

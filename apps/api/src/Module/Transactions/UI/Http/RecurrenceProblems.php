<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Maps a recurrence problem type to its translated response.
 */
final readonly class RecurrenceProblems
{
    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    public function problem(int $status, string $type): Response
    {
        $translation = match ($type) {
            '/problems/recurrences.invalid_query' => 'api.problem.invalid_recurrence_query',
            '/problems/recurrences.not_found' => 'api.problem.recurrence_not_found',
            '/problems/recurrences.forbidden' => 'api.problem.recurrence_forbidden',
            '/problems/recurrences.archived' => 'api.problem.recurrence_archived',
            '/problems/recurrences.duplicate' => 'api.problem.recurrence_duplicate',
            '/problems/stale-version' => 'api.problem.recurrence_stale_version',
            default => 'api.problem.invalid_recurrence',
        };

        return $this->envelope->problem($status, $translation, $type);
    }
}

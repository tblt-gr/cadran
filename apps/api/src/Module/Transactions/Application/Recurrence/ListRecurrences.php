<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/** A pure read: listing recurrences never generates, extends or settles anything. */
final readonly class ListRecurrences
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(private CallerWorkspaceContext $caller, private TransactionRecurrenceRepository $recurrences)
    {
    }

    /** @return array{items: list<array<string, mixed>>, page: int, perPage: int, total: int} */
    public function __invoke(bool $includeArchived, int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidRecurrenceInput('The requested page is out of bounds.');
        }
        $workspace = $this->caller->resolveContext()->workspace;

        return [
            'items' => array_map(
                static fn (TransactionRecurrence $recurrence): array => RecurrenceView::from($recurrence),
                $this->recurrences->list($workspace, $includeArchived, $perPage, ($page - 1) * $perPage),
            ),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $this->recurrences->count($workspace, $includeArchived),
        ];
    }
}

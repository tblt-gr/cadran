<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/** A pure read: listing recurrences never generates, extends or settles anything. */
final readonly class ListRecurrences
{
    public const int MAX_PAGE_SIZE = 100;

    public function __construct(private CallerWorkspaceContext $caller, private TransactionRecurrenceRepository $recurrences)
    {
    }

    /** @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int} */
    public function __invoke(bool $includeArchived, int $page, int $pageSize): array
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidRecurrenceInput('The requested page is out of bounds.');
        }
        $workspace = $this->caller->resolveContext()->workspace;

        return [
            'items' => array_map(
                static fn (TransactionRecurrence $recurrence): array => RecurrenceView::from($recurrence),
                $this->recurrences->list($workspace, $includeArchived, $pageSize, ($page - 1) * $pageSize),
            ),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $this->recurrences->count($workspace, $includeArchived),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrenceRepository;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceRepository;

/** A bounded pure read; effective LATE state is computed only in the representation. */
final readonly class ListRecurrenceOccurrences
{
    public const int MAX_OCCURRENCES = 1000;
    public const int MAX_WINDOW_MONTHS = 60;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRecurrenceRepository $recurrences,
        private TransactionRecurrenceOccurrenceRepository $occurrences,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function __invoke(string $id, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        if (null === $this->recurrences->find($workspace, $id)) {
            throw new RecurrenceNotFound();
        }
        $today = $this->calendar->today();
        $from ??= $today->modify('-12 months');
        $to ??= $today->modify('+12 months');
        if ($to < $from || $to > $from->modify(sprintf('+%d months', self::MAX_WINDOW_MONTHS))) {
            throw new InvalidRecurrenceInput('The requested occurrence window is out of bounds.');
        }
        $items = array_map(
            static fn (TransactionRecurrenceOccurrence $occurrence): array => OccurrenceView::from($occurrence, $today),
            $this->occurrences->listForRecurrence($workspace, $id, $from, $to, self::MAX_OCCURRENCES),
        );

        return ['items' => $items, 'total' => count($items)];
    }
}

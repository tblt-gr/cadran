<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDetector;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDismissalRepository;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationRepository;

/** Runs the bounded, read-only detection requested by the caller. */
final readonly class DetectRecurrenceCandidates
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private RecurrenceObservationRepository $observations,
        private RecurrenceDismissalRepository $dismissals,
        private RecurrenceDetector $detector,
        private AssetCatalog $assets,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, partial: bool} */
    public function __invoke(): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $window = $this->observations->window(
            $workspace,
            $this->calendar->today()->modify(sprintf('-%d months', RecurrenceDetector::WINDOW_MONTHS)),
            RecurrenceDetector::MAX_OBSERVATIONS,
        );
        $assets = [];
        foreach ($window->observations as $observation) {
            $code = $observation->amount->asset->toString();
            $assets[$code] ??= $this->assets->findByCode($observation->amount->asset)
                ?? throw new \UnexpectedValueException(sprintf('Asset %s is missing from the reference.', $code));
        }
        $scan = $this->detector->detect($window, $assets);
        $dismissed = array_flip($this->dismissals->fingerprints($workspace));

        return [
            'items' => array_values(array_map(
                RecurrenceCandidateView::from(...),
                array_filter($scan->candidates, static fn ($candidate): bool => !isset($dismissed[$candidate->fingerprint])),
            )),
            'partial' => $scan->partial,
        ];
    }
}

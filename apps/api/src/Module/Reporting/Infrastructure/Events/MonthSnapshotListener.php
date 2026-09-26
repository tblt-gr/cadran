<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Events;

use App\Module\Accounts\Application\PeriodClosedEvent;
use App\Module\Accounts\Application\PeriodReopenedEvent;
use App\Module\Reporting\Application\CaptureMonthSnapshot;
use App\Module\Reporting\Application\MonthSnapshotStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the frozen months in step with the closures. It never fails a closing or a reopening: a
 * month whose snapshot is missing is captured by the next annual read. Only the event, the
 * workspace and the month are logged, never an amount.
 */
final readonly class MonthSnapshotListener
{
    public function __construct(
        private CaptureMonthSnapshot $capture,
        private MonthSnapshotStore $snapshots,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onPeriodClosed(PeriodClosedEvent $event): void
    {
        try {
            ($this->capture)($event->workspace, $event->month, $event->closureId);
        } catch (\Throwable) {
            $this->logger->warning('Month snapshot capture failed.', [
                'event' => 'PeriodClosedEvent',
                'workspace_id' => $event->workspace->id,
                'month' => $event->month->key(),
            ]);
        }
    }

    #[AsEventListener]
    public function onPeriodReopened(PeriodReopenedEvent $event): void
    {
        try {
            $this->snapshots->deleteForMonth($event->workspace, $event->month);
        } catch (\Throwable) {
            $this->logger->warning('Month snapshot removal failed.', [
                'event' => 'PeriodReopenedEvent',
                'workspace_id' => $event->workspace->id,
                'month' => $event->month->key(),
            ]);
        }
    }
}

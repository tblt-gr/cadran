<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use Symfony\Component\Clock\ClockInterface;

/**
 * The calendar day the workspace is living in.
 *
 * A forecast is read against a day, not an instant: whether an instalment has
 * passed must not change because the server answers at 23:30 UTC. The zone is
 * the one the rest of this module already books movements against, so a
 * movement and the instalment it settles are never one day apart.
 */
final readonly class WorkspaceCalendar
{
    public function __construct(
        private ClockInterface $clock,
        private CallerWorkspaceContext $caller,
        private WorkspaceTimezoneReader $timezones,
    ) {
    }

    public function today(): \DateTimeImmutable
    {
        $workspace = $this->caller->resolveContext()->workspace;

        return new \DateTimeImmutable(
            $this->clock->now()->setTimezone(new \DateTimeZone($this->timezones->timezone($workspace)))->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\Clock\ClockInterface;

/** The calendar day a workspace is living in, so a boundary never moves with the server's zone. */
final readonly class WorkspaceToday
{
    public function __construct(private ClockInterface $clock, private WorkspaceTimezoneReader $timezones)
    {
    }

    public function __invoke(WorkspaceScope $workspace): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $this->clock->now()->setTimezone(new \DateTimeZone($this->timezones->timezone($workspace)))->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

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
    public const string TIMEZONE = 'Europe/Paris';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}

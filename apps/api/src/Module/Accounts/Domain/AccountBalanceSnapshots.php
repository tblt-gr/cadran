<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * The dated snapshots of one account, used to refuse a colliding active
 * row and to pick the latest valid figure on a requested day.
 *
 * @phpstan-type SnapshotList list<AccountBalanceSnapshot>
 */
final readonly class AccountBalanceSnapshots
{
    /** @param SnapshotList $snapshots */
    public function __construct(public array $snapshots)
    {
        $active = [];
        foreach ($this->snapshots as $snapshot) {
            if (!$snapshot->isActive()) {
                continue;
            }
            $key = $snapshot->collisionKey();
            if (isset($active[$key])) {
                $active[$key]->assertCompatibleWith($snapshot);
                continue;
            }
            $active[$key] = $snapshot;
        }
    }

    /**
     * The active snapshot that still answers on $asOf: latest day on or
     * before that date, then the most recently recorded source on that day.
     */
    public function latestActiveOn(\DateTimeImmutable $asOf): ?AccountBalanceSnapshot
    {
        $requested = $asOf->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $candidates = array_values(array_filter(
            $this->snapshots,
            static function (AccountBalanceSnapshot $snapshot) use ($requested): bool {
                return $snapshot->isActive()
                    && $snapshot->asOf->format('Y-m-d') <= $requested;
            },
        ));

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static function (AccountBalanceSnapshot $left, AccountBalanceSnapshot $right): int {
            $byDay = $right->asOf->format('Y-m-d') <=> $left->asOf->format('Y-m-d');
            if (0 !== $byDay) {
                return $byDay;
            }

            return $right->recordedAt <=> $left->recordedAt;
        });

        return $candidates[0];
    }
}

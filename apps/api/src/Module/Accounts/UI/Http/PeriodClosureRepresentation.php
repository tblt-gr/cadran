<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\PeriodClosingBlocker;
use App\Module\Accounts\Application\PeriodStatusView;
use App\Module\Accounts\Domain\PeriodClosure;

final readonly class PeriodClosureRepresentation
{
    /** @return array<string, mixed> */
    public static function one(PeriodClosure $closure): array
    {
        return [
            'id' => $closure->id,
            'period' => $closure->month->key(),
            'closedAt' => $closure->closedAt->format(\DATE_ATOM),
            'closedBy' => $closure->closedBy,
            'reopenedAt' => $closure->reopenedAt?->format(\DATE_ATOM),
            'reopenReason' => $closure->reopenReason,
            'active' => $closure->isActive(),
            'version' => $closure->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function status(PeriodStatusView $status): array
    {
        return [
            'period' => $status->month->key(),
            'closed' => null !== $status->closure,
            'ended' => $status->ended,
            'closure' => null === $status->closure ? null : self::one($status->closure),
            'blockers' => self::blockers($status->blockers),
        ];
    }

    /**
     * @param list<PeriodClosingBlocker> $blockers
     *
     * @return list<array{condition: string, count: int}>
     */
    public static function blockers(array $blockers): array
    {
        return array_map(
            static fn (PeriodClosingBlocker $blocker): array => ['condition' => $blocker->condition->value, 'count' => $blocker->count],
            $blockers,
        );
    }
}

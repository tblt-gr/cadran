<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * One exclusive weight. A calculable share carries the exact ratio and the
 * same figure as a percent; a non-calculable share is null on both, never a
 * fabricated `0`.
 */
final readonly class NetWorthShare
{
    public function __construct(
        public ?DecimalValue $ratio,
        public ?DecimalValue $percent,
        public ?NetWorthShareReason $reason,
    ) {
        if (null !== $reason && (null !== $ratio || null !== $percent)) {
            throw new \InvalidArgumentException('A non-calculable share cannot carry a figure.');
        }

        if (null === $reason && (null === $ratio) !== (null === $percent)) {
            throw new \InvalidArgumentException('A calculable share carries both a ratio and a percent.');
        }
    }

    public static function of(DecimalValue $ratio, DecimalValue $percent): self
    {
        return new self($ratio, $percent, null);
    }

    public static function none(?NetWorthShareReason $reason): self
    {
        return new self(null, null, $reason);
    }
}

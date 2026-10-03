<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Cash;

use App\Module\Foundation\Domain\DecimalValue;

/** A cash inconsistency stated, never hidden and never blocking: its exact count and at most {@see self::MAX_SOURCES} sources. */
final readonly class CashWarning
{
    public const int MAX_SOURCES = 20;

    /** @param list<string> $sourceIds */
    public function __construct(
        public CashWarningCode $code,
        public int $count,
        public array $sourceIds,
        public ?\DateTimeImmutable $firstDate = null,
        public ?DecimalValue $amount = null,
    ) {
    }

    /** @param list<string> $ids every source, in the order they are listed */
    public static function of(CashWarningCode $code, array $ids, ?\DateTimeImmutable $firstDate = null, ?DecimalValue $amount = null): self
    {
        return new self($code, count($ids), array_slice($ids, 0, self::MAX_SOURCES), $firstDate, $amount);
    }
}

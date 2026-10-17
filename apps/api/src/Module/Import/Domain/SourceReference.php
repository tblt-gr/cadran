<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/**
 * `wb:{year}:{mm}:{block}:{labelKey}:{ordinal}`: where a created record comes
 * from. The unique index on transactions protects it, and the replay check of a
 * whole year reads its `wb:{year}:` prefix.
 */
final readonly class SourceReference
{
    private const string PREFIX = 'wb';

    /** The length of the source reference column of a transaction. */
    private const int MAX_LENGTH = 128;

    public function __construct(
        public int $year,
        public int $month,
        public Block $block,
        public string $labelKey,
        public int $ordinal,
    ) {
        if ($year < 1000 || $year > 9999) {
            throw new \InvalidArgumentException('A source reference year has four digits.');
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('A source reference month is between 1 and 12.');
        }
        if ($ordinal < 1) {
            throw new \InvalidArgumentException('A source reference ordinal starts at 1.');
        }
        // The key sits between two colons: a colon in it would make two references ambiguous.
        if (1 !== preg_match('/^[A-Za-z0-9-]+$/D', $labelKey)) {
            throw new \InvalidArgumentException('A source reference label key is made of letters, digits and hyphens.');
        }
    }

    /**
     * A reference that would exceed the column of the transaction's source reference keeps the start of its label key
     * and ends it with the first eight hex characters of the SHA-256 of the whole key: it fits, never changes for a
     * given label and does not collide with another long label that shares its beginning.
     */
    public function toString(): string
    {
        $head = sprintf('%s:%04d:%02d:%s:', self::PREFIX, $this->year, $this->month, $this->block->value);
        $tail = ':'.$this->ordinal;
        $room = self::MAX_LENGTH - strlen($head) - strlen($tail);
        $labelKey = $this->labelKey;
        if (strlen($labelKey) > $room) {
            $labelKey = substr($labelKey, 0, $room - 9).'-'.substr(hash('sha256', $this->labelKey), 0, 8);
        }

        return $head.$labelKey.$tail;
    }
}

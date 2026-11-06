<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** The label to give: one value for every row, or one value per transaction. */
final readonly class BulkLabelChange
{
    /** @param array<string, string>|null $each value per transaction identifier, null for the same value everywhere */
    private function __construct(private ?string $same, private ?array $each)
    {
    }

    public static function same(string $value): self
    {
        return new self($value, null);
    }

    /** @param array<string, string> $values */
    public static function each(array $values): self
    {
        return new self(null, $values);
    }

    /** Trimmed; '' when the row is asked to be emptied or has no value of its own. */
    public function valueFor(string $transactionId): string
    {
        return trim($this->each[$transactionId] ?? $this->same ?? '');
    }

    /** @return array{same: ?string, each: ?array<string, string>} */
    public function canonical(): array
    {
        $each = $this->each;
        if (null !== $each) {
            ksort($each);
        }

        return ['same' => $this->same, 'each' => $each];
    }

    /**
     * The transactions a per-row label names, null for one label shared by all.
     *
     * @return list<string>|null
     */
    public function eachIds(): ?array
    {
        return null === $this->each ? null : array_map(strval(...), array_keys($this->each));
    }
}

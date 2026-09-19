<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The verified state of one month of a workspace.
 *
 * A closure is never deleted: reopening stamps it with when and why, so the
 * trail of closures of a month stays readable, and a later closing is a new
 * row. At most one row per month is active, and only the active one refuses
 * writes.
 */
final readonly class PeriodClosure
{
    public const int MAX_REASON_LENGTH = 200;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public CalendarMonth $month,
        public \DateTimeImmutable $closedAt,
        public string $closedBy,
        public int $version,
        public ?\DateTimeImmutable $reopenedAt = null,
        public ?string $reopenReason = null,
    ) {
        if ('' === $id || '' === $closedBy) {
            throw new InvalidPeriodClosure('A closure needs an identifier and an author.');
        }
        if ($version < 1) {
            throw new InvalidPeriodClosure('A closure version must be positive.');
        }
        if ((null === $reopenedAt) !== (null === $reopenReason)) {
            throw new InvalidPeriodClosure('A reopening carries both its date and its reason.');
        }
        if (null !== $reopenedAt && $reopenedAt < $closedAt) {
            throw new InvalidPeriodClosure('A period cannot be reopened before it was closed.');
        }
    }

    public function isActive(): bool
    {
        return null === $this->reopenedAt;
    }

    public function reopen(string $reason, \DateTimeImmutable $at): self
    {
        if (!$this->isActive()) {
            throw new InvalidPeriodClosure('A reopened period cannot be reopened again.');
        }
        $reason = self::normalisedReason($reason);

        return new self($this->id, $this->workspace, $this->month, $this->closedAt, $this->closedBy, $this->version + 1, $at, $reason);
    }

    /** A reason is what a reviewer reads later: trimmed, non-empty and bounded. */
    public static function normalisedReason(string $reason): string
    {
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidPeriodClosure('A reason is required and holds at most 200 characters.');
        }

        return $reason;
    }
}

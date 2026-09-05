<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One dated observed balance of a single account.
 *
 * The figure is stored as submitted: trailing zeros stay, a zero is a real
 * balance, and a missing valuation is the absence of this row — never a
 * silent 0. Two active snapshots of the same account, day and source would
 * leave that day with two answers, so the later one supersedes the earlier
 * instead of sitting beside it.
 */
final readonly class AccountBalanceSnapshot
{
    public const int MAX_COMMENT_LENGTH = 200;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public \DateTimeImmutable $asOf,
        public AssetAmount $amount,
        public BalanceSnapshotSource $source,
        public ReconciliationStatus $reconciliationStatus,
        public ?string $comment,
        public int $version,
        public \DateTimeImmutable $recordedAt,
        public string $recordedBy,
        public ?\DateTimeImmutable $supersededAt = null,
    ) {
        self::assertIdentifier($id, 'A snapshot identifier');
        self::assertIdentifier($accountId, 'A snapshot account identifier');
        self::assertIdentifier($recordedBy, 'A snapshot author identifier');
        self::assertBusinessDay($asOf);
        self::assertComment($comment);

        if ($version < 1) {
            throw new InvalidAccountBalanceSnapshot('A snapshot version must be positive.');
        }

        if (self::utcDay($asOf) > self::utcDay($recordedAt)) {
            throw new InvalidAccountBalanceSnapshot('A snapshot cannot be dated in the future of when it was recorded.');
        }

        if (null !== $supersededAt && $supersededAt < $recordedAt) {
            throw new InvalidAccountBalanceSnapshot('A snapshot cannot be superseded before it was recorded.');
        }
    }

    public function isActive(): bool
    {
        return null === $this->supersededAt;
    }

    /**
     * The previous figure stays on the trail; it just stops answering. The
     * replacement is the new active row for that account, day and source.
     */
    public function supersededBy(self $replacement): self
    {
        if (!$this->isActive()) {
            throw new InvalidAccountBalanceSnapshot('A superseded snapshot cannot be superseded again.');
        }

        if ($this->accountId !== $replacement->accountId
            || self::utcDay($this->asOf) !== self::utcDay($replacement->asOf)
            || $this->source !== $replacement->source
        ) {
            throw new InvalidAccountBalanceSnapshot('A snapshot can only be superseded by another of the same account, date and source.');
        }

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            accountId: $this->accountId,
            asOf: $this->asOf,
            amount: $this->amount,
            source: $this->source,
            reconciliationStatus: $this->reconciliationStatus,
            comment: $this->comment,
            version: $this->version + 1,
            recordedAt: $this->recordedAt,
            recordedBy: $this->recordedBy,
            supersededAt: $replacement->recordedAt,
        );
    }

    /**
     * Two active snapshots of the same account, day and source cannot both
     * answer. A different source on the same day is a second claim, not a
     * collision; a superseded row has already left the floor.
     */
    public function assertCompatibleWith(self $other): void
    {
        if (!$this->isActive() || !$other->isActive()) {
            return;
        }

        if ($this->id === $other->id) {
            return;
        }

        if ($this->accountId === $other->accountId
            && self::utcDay($this->asOf) === self::utcDay($other->asOf)
            && $this->source === $other->source
        ) {
            throw new ConflictingAccountBalanceSnapshot('An active snapshot already exists for this account, date and source.');
        }
    }

    private static function assertIdentifier(string $id, string $subject): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidAccountBalanceSnapshot(sprintf('%s must be a canonical UUID.', $subject));
        }
    }

    private static function assertBusinessDay(\DateTimeImmutable $date): void
    {
        if ('00:00:00.000000' !== $date->format('H:i:s.u') || 0 !== $date->getOffset()) {
            throw new InvalidAccountBalanceSnapshot('A snapshot date is a UTC calendar day.');
        }
    }

    private static function assertComment(?string $comment): void
    {
        if (null === $comment) {
            return;
        }

        if ($comment !== trim($comment) || '' === $comment || mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new InvalidAccountBalanceSnapshot(sprintf('A snapshot comment must contain between 1 and %d characters.', self::MAX_COMMENT_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $comment)) {
            throw new InvalidAccountBalanceSnapshot('A snapshot comment cannot contain control characters.');
        }
    }

    private static function utcDay(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }
}

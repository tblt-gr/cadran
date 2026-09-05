<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One dated rule value recorded on a single account, in front of whatever that
 * account inherits.
 *
 * It exists because the authority an account follows is sometimes wrong about
 * that account. A bank pays half a point above the published rate on a
 * negotiated contract; a plan was opened under a ceiling the catalogue has
 * since revised. Recording that locally must not edit the catalogue, must not
 * edit the workspace model, and must stay recognisable as a figure the holder
 * typed rather than one anybody published.
 *
 * Three things therefore travel with the value and are not optional: the dates
 * it applies over, the reason it was recorded, and who recorded it. Without
 * them an override is an unexplained divergence from a sourced figure, which
 * is the drift this class exists to make visible.
 *
 * Withdrawing an override is not the same as ending it. Ending it is a dated
 * fact — {@see EffectivePeriod::$validTo} — and the account resolves against
 * the override up to that day for ever after. Withdrawing says the override
 * should not have applied at all: it stops answering on every date, including
 * past ones, and the inherited rule takes over again. The row is kept either
 * way, so the trail still shows what was claimed, by whom and why.
 */
final readonly class AccountRuleOverride
{
    public const int MAX_REASON_LENGTH = 200;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public RuleKind $kind,
        public DeclaredRuleValue $value,
        public EffectivePeriod $period,
        public string $reason,
        public string $authorId,
        public \DateTimeImmutable $recordedAt,
        public ?\DateTimeImmutable $withdrawnAt = null,
        public ?string $withdrawnBy = null,
    ) {
        self::assertIdentifier($id, 'An override identifier');
        self::assertIdentifier($accountId, 'An overridden account identifier');
        self::assertIdentifier($authorId, 'An override author identifier');
        self::assertReason($reason);

        if ($kind->valueType() !== $value->type) {
            throw new InvalidAccountRuleOverride(sprintf('A %s override carries a %s value.', $kind->value, $kind->valueType()->value));
        }

        if ((null === $withdrawnAt) !== (null === $withdrawnBy)) {
            throw new InvalidAccountRuleOverride('A withdrawn override names who withdrew it, and a standing one names nobody.');
        }

        if (null !== $withdrawnBy) {
            self::assertIdentifier($withdrawnBy, 'An override withdrawer identifier');
        }

        if (null !== $withdrawnAt && $withdrawnAt < $recordedAt) {
            throw new InvalidAccountRuleOverride('An override cannot be withdrawn before it was recorded.');
        }
    }

    /** Whether the override still answers at all, on any date. */
    public function isStanding(): bool
    {
        return null === $this->withdrawnAt;
    }

    /**
     * Whether this override is the value in force on a business date. A
     * withdrawn override answers false for every date, which is what makes the
     * inherited rule visible again once it is gone.
     */
    public function appliesOn(\DateTimeImmutable $businessDate): bool
    {
        return $this->isStanding() && $this->period->covers($businessDate);
    }

    public function withdrawnBy(string $actorId, \DateTimeImmutable $at): self
    {
        if (!$this->isStanding()) {
            throw new InvalidAccountRuleOverride('This override was already withdrawn.');
        }

        return new self(
            $this->id,
            $this->workspace,
            $this->accountId,
            $this->kind,
            $this->value,
            $this->period,
            $this->reason,
            $this->authorId,
            $this->recordedAt,
            $at,
            $actorId,
        );
    }

    /**
     * The reason is prose the holder writes, so it is bounded and stripped of
     * control characters rather than matched against a vocabulary: no list of
     * legitimate reasons for diverging from a published figure could be
     * written in advance, and refusing an unlisted one would push the holder
     * to record no reason at all.
     */
    private static function assertReason(string $reason): void
    {
        if ($reason !== trim($reason) || '' === $reason || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidAccountRuleOverride(sprintf('An override reason must contain between 1 and %d characters.', self::MAX_REASON_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $reason)) {
            throw new InvalidAccountRuleOverride('An override reason cannot contain control characters.');
        }
    }

    private static function assertIdentifier(string $value, string $subject): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new InvalidAccountRuleOverride(sprintf('%s must be a canonical UUID.', $subject));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RuleKind;

/**
 * Every override ever recorded on one account, withdrawn ones included.
 *
 * The invariant this class exists for: two standing overrides of the same kind
 * never cover the same day. Without it, "the ceiling this account claims on
 * 12 March" would have two answers and the resolution would silently pick one.
 *
 * A withdrawn override is kept in the set and constrains nothing. It has to be
 * kept, because it is the trail of what was claimed and why; it has to
 * constrain nothing, because withdrawing is precisely the act of saying the
 * period should never have been claimed, and re-recording the corrected one
 * over the same dates is the normal next step.
 */
final readonly class AccountRuleOverrides
{
    /**
     * An account is a small set of local corrections, not a history table. The
     * bound keeps one account from turning every rule resolution into an
     * unbounded read.
     */
    public const int MAX_OVERRIDES = 100;

    /** @var list<AccountRuleOverride> */
    public array $overrides;

    /**
     * @param list<AccountRuleOverride> $overrides
     */
    public function __construct(array $overrides)
    {
        $seen = [];
        foreach ($overrides as $index => $override) {
            if (isset($seen[$override->id])) {
                throw new InvalidAccountRuleOverride('An override appears twice.');
            }

            $seen[$override->id] = true;

            if (!$override->isStanding()) {
                continue;
            }

            foreach (array_slice($overrides, $index + 1) as $other) {
                if ($other->isStanding() && $override->kind === $other->kind && $override->period->overlaps($other->period)) {
                    throw new InvalidAccountRuleOverride(sprintf('Two %s overrides cover the same dates.', $override->kind->value));
                }
            }
        }

        if (count($overrides) > self::MAX_OVERRIDES) {
            throw new InvalidAccountRuleOverride(sprintf('An account records at most %d overrides.', self::MAX_OVERRIDES));
        }

        // A stable order keeps two identical reads byte-identical: by rule kind
        // as the kinds are declared, then oldest period first, then by
        // identifier so two periods recorded for the same dates — one of them
        // withdrawn — never swap places between reads.
        $ordered = $overrides;
        usort($ordered, static function (AccountRuleOverride $left, AccountRuleOverride $right): int {
            $byKind = self::ordinal($left->kind) <=> self::ordinal($right->kind);
            if (0 !== $byKind) {
                return $byKind;
            }

            $byStart = $left->period->validFrom <=> $right->period->validFrom;

            return 0 !== $byStart ? $byStart : $left->id <=> $right->id;
        });

        $this->overrides = $ordered;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function find(string $id): ?AccountRuleOverride
    {
        foreach ($this->overrides as $override) {
            if ($override->id === $id) {
                return $override;
            }
        }

        return null;
    }

    /**
     * The overrides in force on a business date: at most one per kind, by
     * construction of the non-overlap invariant.
     *
     * @return array<string, AccountRuleOverride> keyed by rule kind
     */
    public function effectiveOn(\DateTimeImmutable $businessDate): array
    {
        $inForce = [];
        foreach ($this->overrides as $override) {
            if ($override->appliesOn($businessDate)) {
                $inForce[$override->kind->value] = $override;
            }
        }

        return $inForce;
    }

    /**
     * Records one more override. Unlike a product model, nothing is closed on
     * the holder's behalf: an override already covering these dates is refused
     * outright, because silently ending the previous claim would rewrite what
     * the account said about a past date without anybody deciding to.
     */
    public function appended(AccountRuleOverride $override): self
    {
        foreach ($this->overrides as $existing) {
            if ($existing->isStanding() && $existing->kind === $override->kind && $existing->period->overlaps($override->period)) {
                throw new OverlappingAccountRuleOverride(sprintf('A %s override already covers these dates. Withdraw it, or start the new period after it ends.', $override->kind->value));
            }
        }

        return new self([...$this->overrides, $override]);
    }

    public function withdrawn(string $id, string $actorId, \DateTimeImmutable $at): self
    {
        $target = $this->find($id) ?? throw new InvalidAccountRuleOverride('No override carries this identifier on this account.');

        $kept = [];
        foreach ($this->overrides as $existing) {
            $kept[] = $existing->id === $target->id ? $existing->withdrawnBy($actorId, $at) : $existing;
        }

        return new self($kept);
    }

    /** @return list<AccountRuleOverride> */
    public function standing(): array
    {
        return array_values(array_filter(
            $this->overrides,
            static fn (AccountRuleOverride $override): bool => $override->isStanding(),
        ));
    }

    private static function ordinal(RuleKind $kind): int
    {
        foreach (RuleKind::cases() as $position => $case) {
            if ($case === $kind) {
                return $position;
            }
        }

        throw new \LogicException('Every rule kind is one of the declared cases.');
    }
}

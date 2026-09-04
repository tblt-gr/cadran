<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RuleKind;

/**
 * Every dated rule period recorded on one workspace product model.
 *
 * The invariant this class exists for: two periods of the same kind never
 * cover the same day. Without it, "the rate on 12 March" would have two
 * answers and the model would silently pick one.
 *
 * A period is added, never rewritten. Recording a new rate that supersedes an
 * open-ended one closes the old period the day before the new one starts,
 * which states when it stopped applying instead of pretending it never did:
 * reading a statement from last year must still resolve against last year's
 * rate.
 */
final readonly class ModelRuleSchedule
{
    /** @var list<ModelRule> */
    public array $rules;

    /**
     * @param list<ModelRule> $rules
     */
    public function __construct(array $rules)
    {
        $seen = [];
        foreach ($rules as $index => $rule) {
            if (isset($seen[$rule->id])) {
                throw new InvalidProductModel('A model rule period appears twice.');
            }

            $seen[$rule->id] = true;

            foreach (array_slice($rules, $index + 1) as $other) {
                if ($rule->kind === $other->kind && $rule->period->overlaps($other->period)) {
                    throw new InvalidProductModel(sprintf('Two %s periods cover the same dates.', $rule->kind->value));
                }
            }
        }

        // A stable order keeps two identical reads byte-identical and puts the
        // periods on screen in the order the rule kinds are declared rather
        // than in whatever order a row set arrived.
        $ordered = $rules;
        usort($ordered, static function (ModelRule $left, ModelRule $right): int {
            $byKind = self::ordinal($left->kind) <=> self::ordinal($right->kind);
            if (0 !== $byKind) {
                return $byKind;
            }

            return $left->period->validFrom <=> $right->period->validFrom;
        });

        $this->rules = $ordered;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The periods applying on a business date: at most one per kind, by
     * construction of the non-overlap invariant.
     *
     * @return list<ModelRule>
     */
    public function effectiveOn(\DateTimeImmutable $businessDate): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (ModelRule $rule): bool => $rule->period->covers($businessDate),
        ));
    }

    public function hasRateRule(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->kind->statesARate()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a period, closing the open-ended period of the same kind it
     * supersedes. Any other overlap is refused: two periods stating a
     * different ceiling for the same day is a contradiction the model cannot
     * resolve on the holder's behalf.
     */
    public function appended(ModelRule $rule): self
    {
        $kept = [];
        foreach ($this->rules as $existing) {
            if ($existing->kind !== $rule->kind || !$existing->period->overlaps($rule->period)) {
                $kept[] = $existing;
                continue;
            }

            if (null !== $existing->period->validTo || $existing->period->validFrom >= $rule->period->validFrom) {
                throw new InvalidProductModel(sprintf('A %s period already covers these dates. Start the new period after the ones already recorded.', $rule->kind->value));
            }

            $kept[] = $existing->closedBefore($rule->period->validFrom);
        }

        return new self([...$kept, $rule]);
    }

    /**
     * The same periods under fresh identifiers, for a duplicate that must own
     * its rows without inheriting the source's.
     *
     * @param list<string> $ids
     */
    public function copyWithIds(array $ids): self
    {
        if (count($ids) !== count($this->rules)) {
            throw new InvalidProductModel('A duplicated schedule needs one identifier per period.');
        }

        $copies = [];
        foreach ($this->rules as $position => $rule) {
            $copies[] = $rule->copyAs($ids[$position]);
        }

        return new self($copies);
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

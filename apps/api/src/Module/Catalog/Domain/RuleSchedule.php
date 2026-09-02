<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * Every dated rule recorded for one product.
 *
 * The invariant this class exists for: two rules of the same kind never cover
 * the same day. Without it, "the ceiling on 12 March" would have two answers
 * and the catalogue would silently pick one.
 */
final readonly class RuleSchedule
{
    /** @var list<ProductRule> */
    public array $rules;

    /**
     * @param list<ProductRule> $rules
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $index => $rule) {
            foreach (array_slice($rules, $index + 1) as $other) {
                if ($rule->kind === $other->kind && $rule->period->overlaps($other->period)) {
                    throw new InvalidCatalogEntry(sprintf('Two %s rules cover the same dates.', $rule->kind->value));
                }
            }
        }

        // A stable order keeps two identical reads byte-identical, and puts the
        // rules on screen in the order the enum declares them rather than in
        // whatever order a row set arrived.
        $ordered = $rules;
        usort($ordered, static function (ProductRule $left, ProductRule $right): int {
            $byKind = self::ordinal($left->kind) <=> self::ordinal($right->kind);

            return 0 !== $byKind ? $byKind : $left->period->validFrom <=> $right->period->validFrom;
        });

        $this->rules = $ordered;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The rules applying on a business date: at most one per kind, by
     * construction of the non-overlap invariant.
     *
     * @return list<ProductRule>
     */
    public function effectiveOn(\DateTimeImmutable $businessDate): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (ProductRule $rule): bool => $rule->period->covers($businessDate),
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

    public function hasRateRule(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->kind->statesARate()) {
                return true;
            }
        }

        return false;
    }
}

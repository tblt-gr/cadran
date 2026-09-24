<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

/**
 * Which optional category and axis breakdowns one workspace wants to see in
 * its compact monthly recap.
 *
 * This is a display selection and nothing else: it picks rows, never formulas
 * or policy, so the core totals stay published whatever it holds. An empty
 * selection is a deliberate choice and stays distinguishable from the default
 * one, which is why it is never rewritten into the default here.
 */
final readonly class RecapVisibility
{
    /**
     * A personal workspace keeps tens of categories. The cap only stops a
     * stored preference from growing into an unbounded payload.
     */
    public const int MAX_ITEMS = 100;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * @param list<string> $categoryIds
     * @param list<string> $axes
     */
    private function __construct(
        public array $categoryIds,
        public array $axes,
    ) {
    }

    /**
     * @param list<string> $categoryIds
     * @param list<string> $axes
     * @param list<string> $knownAxes
     */
    public static function of(array $categoryIds, array $axes, array $knownAxes): self
    {
        self::assertBounded($categoryIds, 'categories');
        self::assertBounded($axes, 'axes');
        foreach ($categoryIds as $categoryId) {
            if (1 !== preg_match(self::UUID_PATTERN, $categoryId)) {
                throw new InvalidRecapVisibility('A recap category must be an identifier.');
            }
        }
        foreach ($axes as $axis) {
            if (!in_array($axis, $knownAxes, true)) {
                throw new InvalidRecapVisibility('A recap axis must be a defined analytic axis.');
            }
        }

        return new self($categoryIds, $axes);
    }

    /** @param list<string> $knownAxes */
    public static function default(array $knownAxes): self
    {
        return new self([], $knownAxes);
    }

    /** @param list<string> $values */
    private static function assertBounded(array $values, string $what): void
    {
        if (count($values) > self::MAX_ITEMS) {
            throw new InvalidRecapVisibility(sprintf('A recap selection holds at most %d %s.', self::MAX_ITEMS, $what));
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new InvalidRecapVisibility(sprintf('A recap selection names each of its %s once.', $what));
        }
    }
}

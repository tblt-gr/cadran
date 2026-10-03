<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Overview;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

final class OverviewFigures
{
    /** @param list<string|null> $costs */
    public static function gain(string $securitiesValue, array $costs): ?string
    {
        $cost = '0';
        foreach ($costs as $value) {
            if (null === $value) {
                return null;
            }
            $cost = ExactDecimal::addForResponse($cost, $value);
        }

        return ExactDecimal::subtractForResponse($securitiesValue, $cost);
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $items
     *
     * @return array{items: list<array{key: string, label: string, value: string, share: ?string}>, slices: list<array{key: string, label: string, value: string, share: ?string}>}
     */
    public static function allocation(array $items, DecimalValue $total): array
    {
        $positive = $total->compareTo(DecimalValue::zero()) > 0;
        $table = self::withShares($items, $total, $positive);
        if (!$positive) {
            return ['items' => $table, 'slices' => []];
        }
        // Stable tie-breaking makes the smallest-bucket merge deterministic across reads.
        usort($items, static fn (array $left, array $right): int => ExactDecimal::compareForResponse($right['value'], $left['value']) ?: strcmp($left['key'], $right['key']));
        if (count($items) > 8) {
            $others = '0';
            foreach (array_slice($items, 7) as $item) {
                $others = ExactDecimal::addForResponse($others, $item['value']);
            }
            $items = [...array_slice($items, 0, 7), ['key' => 'OTHERS', 'label' => 'Autres', 'value' => $others]];
        }

        return ['items' => $table, 'slices' => self::withShares($items, $total, true)];
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $items
     *
     * @return list<array{key: string, label: string, value: string, share: ?string}>
     */
    private static function withShares(array $items, DecimalValue $total, bool $positive): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = [...$item, 'share' => $positive ? ExactDecimal::divideForResponse($item['value'], $total->toString(), DecimalValue::MAX_SCALE) : null];
        }

        return $result;
    }
}

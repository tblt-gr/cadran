<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\PortfolioOperation;

final class PortfolioExplanation
{
    public const int MAX_SOURCES = 50;

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return list<array{kind: string, id: string, date: string}>
     */
    public static function operations(array $operations): array
    {
        return array_map(static fn (PortfolioOperation $operation): array => [
            'kind' => 'OPERATION', 'id' => $operation->id, 'date' => $operation->details->tradeDate->format('Y-m-d'),
        ], $operations);
    }

    /**
     * @param list<array{kind: string, id: string, date: string}> $sources
     *
     * @return array<string, mixed>
     */
    public static function of(string $from, string $to, string $quality, ?string $reason, array $sources): array
    {
        // Source previews are bounded, while their omitted count stays exact.
        usort($sources, static fn (array $left, array $right): int => [$right['date'], $left['kind'], $left['id']] <=> [$left['date'], $right['kind'], $right['id']]);

        return ['period' => ['from' => $from, 'to' => $to], 'quality' => $quality, 'reason' => $reason,
            'sources' => array_slice($sources, 0, self::MAX_SOURCES), 'truncatedSourceCount' => max(0, count($sources) - self::MAX_SOURCES)];
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Transactions\Application\ListTransactions;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;

/**
 * The syntactic contract of the transaction filters, shared by the listing,
 * the count and the filter scope of a bulk action so they can never drift:
 * one place decides what a well-formed filter looks like.
 */
final readonly class TransactionFilterShape
{
    public const int MAX_MULTI_VALUE_FILTER = 20;
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    /**
     * @param list<string> $accountIds
     * @param list<string> $categoryIds
     * @param list<string> $states
     * @param list<string> $natures
     * @param list<string> $axes
     * @param list<string> $sources
     *
     * @return ListTransactionsQuery|null null when any value is malformed
     */
    public function query(
        ?string $from,
        ?string $to,
        array $accountIds,
        array $categoryIds,
        array $states,
        array $natures,
        array $axes,
        array $sources,
        ?string $includeVoided,
        ?string $includeDescendants,
        ?string $categorization,
        ?string $q,
        ?string $minAmount,
        ?string $maxAmount,
        ?string $assetCode,
        ?string $pageSize = null,
        ?string $cursor = null,
    ): ?ListTransactionsQuery {
        if (count($accountIds) > self::MAX_MULTI_VALUE_FILTER || count($categoryIds) > self::MAX_MULTI_VALUE_FILTER
            || count($states) > self::MAX_MULTI_VALUE_FILTER || count($natures) > self::MAX_MULTI_VALUE_FILTER
            || count($axes) > self::MAX_MULTI_VALUE_FILTER || count($sources) > self::MAX_MULTI_VALUE_FILTER
            || !self::allMatch($accountIds, $this->envelope->isIdentifier(...))
            || !self::allMatch($categoryIds, $this->envelope->isIdentifier(...))
            || !self::allMatch($states, static fn (string $value): bool => null !== TransactionState::tryFrom($value))
            || !self::allMatch($natures, static fn (string $value): bool => null !== TransactionNature::tryFrom($value))
            || !self::allMatch($axes, static fn (string $value): bool => null !== AnalyticAxis::tryFrom($value))
            || !self::allMatch($sources, static fn (string $value): bool => null !== TransactionSource::tryFrom($value))
            || (null !== $from && 1 !== preg_match(self::DATE_PATTERN, $from))
            || (null !== $to && 1 !== preg_match(self::DATE_PATTERN, $to))
            || (null !== $includeVoided && !in_array($includeVoided, ['true', 'false'], true))
            || (null !== $includeDescendants && !in_array($includeDescendants, ['true', 'false'], true))
            || (null !== $pageSize && 1 !== preg_match('/^[0-9]{1,3}$/D', $pageSize))
            || (null !== $cursor && ('' === $cursor || strlen($cursor) > 200))
            || (null !== $categorization && !in_array($categorization, ['ANY', 'NONE'], true))
            || (null !== $q && mb_strlen($q, 'UTF-8') > ListTransactions::MAX_TEXT_QUERY_LENGTH)
            || (null !== $assetCode && '' === $assetCode)) {
            return null;
        }

        return new ListTransactionsQuery(
            from: $from,
            to: $to,
            accountIds: $accountIds,
            states: $states,
            natures: $natures,
            categoryIds: $categoryIds,
            includeDescendants: 'true' === $includeDescendants,
            axes: $axes,
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            assetCode: $assetCode,
            sources: $sources,
            categorizationNone: 'NONE' === $categorization,
            q: $q,
            includeVoided: 'true' === $includeVoided,
            pageSize: null === $pageSize ? null : (int) $pageSize,
            cursor: $cursor,
        );
    }

    /** @param list<string> $values */
    private static function allMatch(array $values, \Closure $predicate): bool
    {
        foreach ($values as $value) {
            if (!$predicate($value)) {
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\BulkCategorizeInput;

/**
 * The body of a bulk change of category: a target category and exactly one
 * scope, either explicit identifiers or filter criteria with the count the
 * user confirmed. Nothing is coerced; any deviation is refused.
 */
final readonly class BulkCategoryPayload
{
    private const array FILTER_FIELDS = [
        'from', 'to', 'accountIds', 'states', 'natures', 'categoryIds', 'includeDescendants', 'axes',
        'minAmount', 'maxAmount', 'assetCode', 'sources', 'categorization', 'q', 'includeVoided',
    ];

    public function __construct(private TransactionHttpEnvelope $envelope, private TransactionFilterShape $shape)
    {
    }

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public function parse(array $body): BulkCategorizeInput
    {
        $payload = TransactionPayload::of($body, ['categoryId', 'scope']);
        $categoryId = $payload->identifier('categoryId');
        $scope = $body['scope'];
        if (!is_array($scope) || !isset($scope['type']) || !is_string($scope['type'])) {
            throw new \UnexpectedValueException('The scope must be an object with a type.');
        }

        return match ($scope['type']) {
            'IDS' => $this->byIds($categoryId, $scope),
            'FILTER' => $this->byFilters($categoryId, $scope),
            default => throw new \UnexpectedValueException('Unknown bulk scope type.'),
        };
    }

    /** @param array<mixed> $scope */
    private function byIds(string $categoryId, array $scope): BulkCategorizeInput
    {
        TransactionPayload::of($scope, ['type', 'ids']);
        $ids = $scope['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || [] === $ids) {
            throw new \UnexpectedValueException('ids must be a non-empty list.');
        }
        // Not bounded here: the use case owns the cap and answers it with its own problem.
        foreach ($ids as $id) {
            if (!is_string($id) || !$this->envelope->isIdentifier($id)) {
                throw new \UnexpectedValueException('Each id must be a canonical UUID.');
            }
        }

        return BulkCategorizeInput::forIds($categoryId, $ids);
    }

    /** @param array<mixed> $scope */
    private function byFilters(string $categoryId, array $scope): BulkCategorizeInput
    {
        TransactionPayload::of($scope, ['type', 'filters', 'expectedCount']);
        $expected = $scope['expectedCount'];
        $filters = $scope['filters'];
        if (!is_int($expected) || $expected < 0 || !is_array($filters) || ([] !== $filters && array_is_list($filters))) {
            throw new \UnexpectedValueException('A filter scope needs filters and a confirmed count.');
        }
        if ([] !== array_diff(array_keys($filters), self::FILTER_FIELDS)) {
            throw new \UnexpectedValueException('Unknown filter field.');
        }

        $query = $this->shape->query(
            self::text($filters, 'from'), self::text($filters, 'to'),
            self::texts($filters, 'accountIds'), self::texts($filters, 'categoryIds'), self::texts($filters, 'states'),
            self::texts($filters, 'natures'), self::texts($filters, 'axes'), self::texts($filters, 'sources'),
            self::flag($filters, 'includeVoided'), self::flag($filters, 'includeDescendants'),
            self::text($filters, 'categorization'), self::text($filters, 'q'),
            self::text($filters, 'minAmount'), self::text($filters, 'maxAmount'), self::text($filters, 'assetCode'),
        ) ?? throw new \UnexpectedValueException('A filter is malformed.');

        return BulkCategorizeInput::forFilters($categoryId, $query, $expected);
    }

    /** @param array<mixed> $filters */
    private static function text(array $filters, string $field): ?string
    {
        $value = $filters[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a string.', $field));
        }

        return $value;
    }

    /**
     * @param array<mixed> $filters
     *
     * @return list<string>
     */
    private static function texts(array $filters, string $field): array
    {
        $value = $filters[$field] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a list.', $field));
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException(sprintf('%s must hold strings.', $field));
            }
        }

        return $value;
    }

    /** @param array<mixed> $filters */
    private static function flag(array $filters, string $field): ?string
    {
        $value = $filters[$field] ?? null;
        if (null !== $value && !is_bool($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a boolean.', $field));
        }

        return null === $value ? null : ($value ? 'true' : 'false');
    }
}

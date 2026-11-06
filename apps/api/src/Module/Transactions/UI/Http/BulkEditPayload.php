<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\BulkEditChanges;
use App\Module\Transactions\Application\BulkEditInput;
use App\Module\Transactions\Application\BulkFieldChange;
use App\Module\Transactions\Application\BulkLabelChange;
use App\Module\Transactions\Domain\PaymentMethod;
use App\Module\Transactions\Domain\Transaction;

/**
 * The body of a bulk edit: the changes to apply and exactly one scope, either
 * explicit identifiers or filter criteria with the count the user confirmed.
 * Nothing is coerced; any deviation is refused.
 */
final readonly class BulkEditPayload
{
    private const int MAX_EACH_VALUES = 100;
    private const array CHANGE_FIELDS = ['label', 'paymentMethod', 'note', 'counterparty', 'categoryId'];
    private const array FILTER_FIELDS = [
        'from', 'to', 'accountIds', 'states', 'natures', 'categoryIds', 'includeDescendants', 'axes',
        'minAmount', 'maxAmount', 'assetCode', 'sources', 'categorization', 'q', 'includeVoided', 'direction', 'categoryType',
    ];

    public function __construct(private TransactionHttpEnvelope $envelope, private TransactionFilterShape $shape)
    {
    }

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public function parse(array $body): BulkEditInput
    {
        TransactionPayload::of($body, ['changes', 'scope']);
        $changes = $this->changes($body['changes']);
        $scope = $body['scope'];
        if (!is_array($scope) || !isset($scope['type']) || !is_string($scope['type'])) {
            throw new \UnexpectedValueException('The scope must be an object with a type.');
        }

        return match ($scope['type']) {
            'IDS' => $this->byIds($changes, $scope),
            'FILTER' => $this->byFilters($changes, $scope),
            default => throw new \UnexpectedValueException('Unknown bulk scope type.'),
        };
    }

    private function changes(mixed $changes): BulkEditChanges
    {
        if (!is_array($changes) || [] === $changes || array_is_list($changes)) {
            throw new \UnexpectedValueException('changes must be an object naming at least one field.');
        }
        if ([] !== array_diff(array_keys($changes), self::CHANGE_FIELDS)) {
            throw new \UnexpectedValueException('Unknown change field.');
        }

        return new BulkEditChanges(
            label: array_key_exists('label', $changes) ? $this->label($changes['label']) : null,
            paymentMethod: array_key_exists('paymentMethod', $changes) ? $this->paymentMethod($changes['paymentMethod']) : null,
            note: array_key_exists('note', $changes) ? $this->fieldText($changes['note'], Transaction::MAX_NOTE_LENGTH) : null,
            counterparty: array_key_exists('counterparty', $changes) ? $this->fieldText($changes['counterparty'], Transaction::MAX_COUNTERPARTY_LENGTH) : null,
            categoryId: array_key_exists('categoryId', $changes) ? TransactionPayload::of(['categoryId' => $changes['categoryId']], ['categoryId'])->identifier('categoryId') : null,
        );
    }

    private function label(mixed $label): BulkLabelChange
    {
        if (!is_array($label) || !isset($label['mode']) || !is_string($label['mode'])) {
            throw new \UnexpectedValueException('label must be an object with a mode.');
        }
        if ('SAME' === $label['mode']) {
            TransactionPayload::of($label, ['mode', 'value']);

            return BulkLabelChange::same($this->boundedText($label['value'], Transaction::MAX_RAW_LABEL_LENGTH));
        }
        if ('EACH' !== $label['mode']) {
            throw new \UnexpectedValueException('Unknown label mode.');
        }
        TransactionPayload::of($label, ['mode', 'values']);
        $values = $label['values'];
        if (!is_array($values) || !array_is_list($values) || [] === $values || count($values) > self::MAX_EACH_VALUES) {
            throw new \UnexpectedValueException('values must hold between 1 and 100 entries.');
        }
        $each = [];
        foreach ($values as $entry) {
            if (!is_array($entry)) {
                throw new \UnexpectedValueException('Each label value must be an object.');
            }
            TransactionPayload::of($entry, ['id', 'value']);
            $id = $entry['id'];
            if (!is_string($id) || !$this->envelope->isIdentifier($id) || isset($each[$id])) {
                throw new \UnexpectedValueException('Each label value names a distinct canonical UUID.');
            }
            $each[$id] = $this->boundedText($entry['value'], Transaction::MAX_RAW_LABEL_LENGTH);
        }

        return BulkLabelChange::each($each);
    }

    private function paymentMethod(mixed $method): BulkFieldChange
    {
        if (null !== $method && (!is_string($method) || null === PaymentMethod::tryFrom($method))) {
            throw new \UnexpectedValueException('Unknown payment method.');
        }

        return new BulkFieldChange($method);
    }

    private function fieldText(mixed $text, int $maximum): BulkFieldChange
    {
        return new BulkFieldChange(null === $text ? null : $this->boundedText($text, $maximum));
    }

    private function boundedText(mixed $text, int $maximum): string
    {
        if (!is_string($text) || mb_strlen($text) > $maximum) {
            throw new \UnexpectedValueException(sprintf('A text must be a string of at most %d characters.', $maximum));
        }

        return $text;
    }

    /** @param array<mixed> $scope */
    private function byIds(BulkEditChanges $changes, array $scope): BulkEditInput
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

        $named = $changes->label?->eachIds();
        if (null !== $named) {
            $selected = array_values(array_unique($ids));
            sort($selected);
            sort($named);
            if ($selected !== $named) {
                throw new \UnexpectedValueException('A label per transaction needs exactly the selected identifiers.');
            }
        }

        return BulkEditInput::forIds($changes, $ids);
    }

    /** @param array<mixed> $scope */
    private function byFilters(BulkEditChanges $changes, array $scope): BulkEditInput
    {
        TransactionPayload::of($scope, ['type', 'filters', 'expectedCount']);
        if (null !== $changes->label?->eachIds()) {
            throw new \UnexpectedValueException('A label per transaction needs an explicit selection.');
        }
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
            self::text($filters, 'direction'), categoryType: self::text($filters, 'categoryType'),
        ) ?? throw new \UnexpectedValueException('A filter is malformed.');

        return BulkEditInput::forFilters($changes, $query, $expected);
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

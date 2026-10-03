<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\OperationInput;

/**
 * Reads the JSON shape of an operation body: declared fields only, strings where
 * a string is expected, and `{value, assetCode}` objects for amounts. A number
 * where a decimal string belongs is refused here, never coerced.
 */
final class OperationPayload
{
    private const array FIELDS = [
        'type', 'tradeDate', 'settlementDate', 'instrumentId', 'quantity', 'unitPrice', 'grossAmount', 'fees', 'taxes',
        'costBasis', 'counterInstrumentId', 'counterQuantity', 'ratio', 'costAllocationRatio', 'reference', 'note',
        'linkedTransactionId',
    ];

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public static function operation(array $body): OperationInput
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, self::FIELDS, true)) {
                throw new \UnexpectedValueException('The operation body carries only declared fields.');
            }
        }
        $ratio = self::object($body, 'ratio', ['numerator', 'denominator']);

        return new OperationInput(
            type: self::requiredString($body, 'type'),
            tradeDate: self::requiredString($body, 'tradeDate'),
            settlementDate: self::string($body, 'settlementDate'),
            instrumentId: self::string($body, 'instrumentId'),
            quantity: self::string($body, 'quantity'),
            unitPrice: self::string($body, 'unitPrice'),
            grossAmount: self::amount($body, 'grossAmount'),
            fees: self::amount($body, 'fees'),
            taxes: self::amount($body, 'taxes'),
            costBasis: self::amount($body, 'costBasis'),
            counterInstrumentId: self::string($body, 'counterInstrumentId'),
            counterQuantity: self::string($body, 'counterQuantity'),
            ratioNumerator: $ratio['numerator'] ?? null,
            ratioDenominator: $ratio['denominator'] ?? null,
            costAllocationRatio: self::string($body, 'costAllocationRatio'),
            reference: self::string($body, 'reference'),
            note: self::string($body, 'note'),
            linkedTransactionId: self::string($body, 'linkedTransactionId'),
        );
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    public static function shape(array $body, array $required, array $optional = []): array
    {
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key) || !in_array($key, [...$required, ...$optional], true)) {
                throw new \UnexpectedValueException('The body carries only declared fields.');
            }
            $fields[$key] = $value;
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $fields)) {
                throw new \UnexpectedValueException(sprintf('%s is required.', $field));
            }
        }

        return $fields;
    }

    /** @param array<mixed> $body */
    private static function requiredString(array $body, string $field): string
    {
        return self::string($body, $field) ?? throw new \UnexpectedValueException(sprintf('%s is required.', $field));
    }

    /** @param array<mixed> $body */
    private static function string(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a string.', $field));
        }

        return $value;
    }

    /** @param array<mixed> $body */
    private static function amount(array $body, string $field): ?AmountInput
    {
        $fields = self::object($body, $field, ['value', 'assetCode']);
        if (null === $fields) {
            return null;
        }
        if (!isset($fields['value'], $fields['assetCode'])) {
            throw new \UnexpectedValueException(sprintf('%s needs a value and an assetCode.', $field));
        }

        return new AmountInput($fields['value'], $fields['assetCode']);
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $keys
     *
     * @return array<string, string>|null
     */
    private static function object(array $body, string $field, array $keys): ?array
    {
        $value = $body[$field] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_array($value) || array_is_list($value) && [] !== $value) {
            throw new \UnexpectedValueException(sprintf('%s must be an object.', $field));
        }
        $typed = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !in_array($key, $keys, true) || !is_string($item)) {
                throw new \UnexpectedValueException(sprintf('%s carries only string members %s.', $field, implode(', ', $keys)));
            }
            $typed[$key] = $item;
        }
        if (count($typed) !== count($keys)) {
            throw new \UnexpectedValueException(sprintf('%s needs %s.', $field, implode(' and ', $keys)));
        }

        return $typed;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\PriceInput;
use App\Module\Portfolio\Application\ValuationInput;

/**
 * Reads the JSON shape of a valuation body: declared fields only, strings where a string is expected and
 * `{value, assetCode}` objects for amounts. A number where a decimal string belongs is refused, never coerced.
 */
final class ValuationPayload
{
    public const int MAX_PRICES = 500;

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public static function valuation(array $body): ValuationInput
    {
        $fields = OperationPayload::shape(
            $body,
            ['valuedOn', 'detail'],
            ['totalValue', 'prices', 'observedCash', 'observedTotal', 'note', 'version'],
        );

        return new ValuationInput(
            valuedOn: self::requiredString($fields, 'valuedOn'),
            detail: self::requiredString($fields, 'detail'),
            totalValue: self::amount($fields, 'totalValue'),
            prices: self::prices($fields),
            observedCash: self::amount($fields, 'observedCash'),
            observedTotal: self::amount($fields, 'observedTotal'),
            note: self::string($fields, 'note'),
            version: self::integer($fields, 'version'),
        );
    }

    /** @param array<string, mixed> $fields */
    private static function requiredString(array $fields, string $name): string
    {
        return self::string($fields, $name) ?? throw new \UnexpectedValueException(sprintf('%s is required.', $name));
    }

    /** @param array<string, mixed> $fields */
    private static function string(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a string.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private static function integer(array $fields, string $name): ?int
    {
        $value = $fields[$name] ?? null;
        if (null !== $value && !is_int($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an integer.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private static function amount(array $fields, string $name): ?AmountInput
    {
        $value = $fields[$name] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an object.', $name));
        }
        $members = OperationPayload::shape($value, ['value', 'assetCode']);
        if (!is_string($members['value']) || !is_string($members['assetCode'])) {
            throw new \UnexpectedValueException(sprintf('%s needs a string value and a string assetCode.', $name));
        }

        return new AmountInput($members['value'], $members['assetCode']);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<PriceInput>|null
     */
    private static function prices(array $fields): ?array
    {
        $value = $fields['prices'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PRICES) {
            throw new \UnexpectedValueException('prices must be a list of at most 500 entries.');
        }
        $entries = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new \UnexpectedValueException('A price entry must be an object.');
            }
            $members = OperationPayload::shape($entry, ['instrumentId', 'price'], ['priceDate']);
            foreach (['instrumentId', 'price', 'priceDate'] as $name) {
                $member = $members[$name] ?? null;
                if (null !== $member && !is_string($member)) {
                    throw new \UnexpectedValueException(sprintf('A price entry %s must be a string.', $name));
                }
            }
            if (!is_string($members['instrumentId'])) {
                throw new \UnexpectedValueException('A price entry names its instrument.');
            }
            $price = $members['price'];
            $priceDate = $members['priceDate'] ?? null;
            $entries[] = new PriceInput($members['instrumentId'], is_string($price) ? $price : null, is_string($priceDate) ? $priceDate : null);
        }

        return $entries;
    }
}

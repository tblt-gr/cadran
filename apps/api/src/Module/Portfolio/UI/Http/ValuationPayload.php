<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
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
     * @throws MalformedPayload
     */
    public static function valuation(array $body): ValuationInput
    {
        $payload = JsonPayload::of(
            $body,
            ['valuedOn', 'detail'],
            ['totalValue', 'prices', 'observedCash', 'observedTotal', 'note', 'version'],
        );

        return new ValuationInput(
            valuedOn: $payload->string('valuedOn'),
            detail: $payload->string('detail'),
            totalValue: OperationPayload::amountInput($payload, 'totalValue'),
            prices: self::prices($payload->raw('prices')),
            observedCash: OperationPayload::amountInput($payload, 'observedCash'),
            observedTotal: OperationPayload::amountInput($payload, 'observedTotal'),
            note: $payload->nullableString('note'),
            version: $payload->nullableInteger('version'),
        );
    }

    /** @return list<PriceInput>|null */
    private static function prices(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PRICES) {
            throw new MalformedPayload('prices must be a list of at most 500 entries.');
        }
        $entries = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new MalformedPayload('A price entry must be an object.');
            }
            $price = JsonPayload::of($entry, ['instrumentId', 'price'], ['priceDate']);
            $entries[] = new PriceInput($price->string('instrumentId'), $price->nullableString('price'), $price->nullableString('priceDate'));
        }

        return $entries;
    }
}

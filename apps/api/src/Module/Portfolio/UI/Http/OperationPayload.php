<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\OperationInput;
use App\Module\Portfolio\Application\OperationLegInput;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;

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
        'linkedTransactionId', 'legs', 'withheldIncomeTax', 'withheldSocialContributions', 'interestBasis', 'rateYear',
    ];
    private const int MAX_LEGS = 20;

    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function operation(array $body): OperationInput
    {
        $payload = JsonPayload::of($body, [], self::FIELDS);
        $rateYear = $payload->nullableInteger('rateYear');
        $ratio = $payload->nullableObject('ratio', ['numerator', 'denominator']);

        return new OperationInput(
            interestBasis: $payload->nullableString('interestBasis'),
            rateYear: $rateYear,
            type: $payload->string('type'),
            tradeDate: $payload->string('tradeDate'),
            settlementDate: $payload->nullableString('settlementDate'),
            instrumentId: $payload->nullableString('instrumentId'),
            quantity: $payload->nullableString('quantity'),
            unitPrice: $payload->nullableString('unitPrice'),
            grossAmount: self::amountInput($payload, 'grossAmount'),
            fees: self::amountInput($payload, 'fees'),
            taxes: self::amountInput($payload, 'taxes'),
            costBasis: self::amountInput($payload, 'costBasis'),
            counterInstrumentId: $payload->nullableString('counterInstrumentId'),
            counterQuantity: $payload->nullableString('counterQuantity'),
            ratioNumerator: $ratio?->string('numerator'),
            ratioDenominator: $ratio?->string('denominator'),
            costAllocationRatio: $payload->nullableString('costAllocationRatio'),
            reference: $payload->nullableString('reference'),
            note: $payload->nullableString('note'),
            linkedTransactionId: $payload->nullableString('linkedTransactionId'),
            legs: self::legs($payload->raw('legs') ?? []),
            withheldIncomeTax: self::amountInput($payload, 'withheldIncomeTax'),
            withheldSocialContributions: self::amountInput($payload, 'withheldSocialContributions'),
        );
    }

    /** An optional `{value, assetCode}` object, shared by the portfolio bodies. */
    public static function amountInput(JsonPayload $payload, string $field): ?AmountInput
    {
        $amount = $payload->nullableAmount($field);

        return null === $amount ? null : new AmountInput($amount['value'], $amount['assetCode']);
    }

    /** @return list<OperationLegInput> */
    private static function legs(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new MalformedPayload('Legs must be a list.');
        }
        if (count($rows) > self::MAX_LEGS) {
            throw new InvalidPortfolioOperation('At most twenty support legs are allowed.', 'too_many_operation_legs');
        }
        $legs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new MalformedPayload('A leg is an object.');
            }
            $leg = JsonPayload::of($row, ['direction', 'instrumentId', 'amount'], ['quantity', 'unitPrice']);
            $legs[] = new OperationLegInput(
                $leg->string('direction'), $leg->string('instrumentId'),
                $leg->nullableString('quantity'), $leg->nullableString('unitPrice'),
                self::amountInput($leg, 'amount') ?? throw new MalformedPayload('A leg amount is required.'),
            );
        }

        return $legs;
    }
}

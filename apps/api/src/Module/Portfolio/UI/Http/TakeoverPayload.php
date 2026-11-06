<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\Takeover\TakeoverInput;
use App\Module\Portfolio\Application\Takeover\TakeoverLineInput;

/**
 * Reads the JSON shape of a takeover body: declared fields only and strings where a decimal belongs. A number is
 * refused, never coerced; every business rule is applied by the resolver.
 */
final class TakeoverPayload
{
    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public static function takeover(array $body): TakeoverInput
    {
        $fields = OperationPayload::shape($body, ['takenOverOn', 'lines'], ['cash']);
        $lines = $fields['lines'];
        if (!is_string($fields['takenOverOn']) || !is_array($lines) || !array_is_list($lines)) {
            throw new \UnexpectedValueException('takenOverOn is a date and lines a list.');
        }
        if (count($lines) > TakeoverInput::MAX_LINES) {
            // Beyond the bound nothing is read: the resolver reports the count.
            return new TakeoverInput($fields['takenOverOn'], array_fill(0, TakeoverInput::MAX_LINES + 1, new TakeoverLineInput('', '', null, '', null)), self::nullableString($fields, 'cash'));
        }

        return new TakeoverInput($fields['takenOverOn'], array_map(self::line(...), $lines), self::nullableString($fields, 'cash'));
    }

    private static function line(mixed $line): TakeoverLineInput
    {
        if (!is_array($line)) {
            throw new \UnexpectedValueException('A line is an object.');
        }
        $fields = OperationPayload::shape($line, ['instrumentId', 'quantity', 'price'], ['costBasis', 'priceDate']);
        foreach (['instrumentId', 'quantity', 'price'] as $name) {
            if (!is_string($fields[$name])) {
                throw new \UnexpectedValueException(sprintf('%s is a string.', $name));
            }
        }

        return new TakeoverLineInput($fields['instrumentId'], $fields['quantity'], self::nullableString($fields, 'costBasis'), $fields['price'], self::nullableString($fields, 'priceDate'));
    }

    /** @param array<string, mixed> $fields */
    private static function nullableString(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s is a string or null.', $name));
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
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
     * @throws MalformedPayload
     */
    public static function takeover(array $body): TakeoverInput
    {
        $payload = JsonPayload::of($body, ['takenOverOn', 'lines'], ['cash']);
        $takenOverOn = $payload->string('takenOverOn');
        $lines = $payload->raw('lines');
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new MalformedPayload('lines must be a list.');
        }
        if (count($lines) > TakeoverInput::MAX_LINES) {
            // Beyond the bound nothing is read: the resolver reports the count.
            return new TakeoverInput($takenOverOn, array_fill(0, TakeoverInput::MAX_LINES + 1, new TakeoverLineInput('', '', null, '', null)), $payload->nullableString('cash'));
        }

        return new TakeoverInput($takenOverOn, array_map(self::line(...), $lines), $payload->nullableString('cash'));
    }

    private static function line(mixed $line): TakeoverLineInput
    {
        if (!is_array($line)) {
            throw new MalformedPayload('A line is an object.');
        }
        $payload = JsonPayload::of($line, ['instrumentId', 'quantity', 'price'], ['costBasis', 'priceDate']);

        return new TakeoverLineInput(
            $payload->string('instrumentId'),
            $payload->string('quantity'),
            $payload->nullableString('costBasis'),
            $payload->string('price'),
            $payload->nullableString('priceDate'),
        );
    }
}

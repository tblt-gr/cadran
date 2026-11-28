<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Portfolio\Application\CreateInstrumentInput;
use App\Module\Portfolio\Application\UpdateInstrumentInput;

/** Reads the JSON shape of the instrument bodies: declared fields only, nothing coerced. */
final class InstrumentPayload
{
    private const array REQUIRED = ['name', 'instrumentType', 'currencyCode'];
    private const array OPTIONAL = [
        'isin', 'ticker', 'venueMic', 'assetClass', 'region', 'quantityPrecision', 'pricePrecision', 'confirmDuplicate',
    ];

    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function create(array $body): CreateInstrumentInput
    {
        $payload = JsonPayload::of($body, self::REQUIRED, self::OPTIONAL);

        return new CreateInstrumentInput(
            $payload->string('name'), $payload->nullableString('isin'), $payload->nullableString('ticker'),
            $payload->nullableString('venueMic'), $payload->string('instrumentType'), $payload->nullableString('assetClass'),
            $payload->nullableString('region'), $payload->string('currencyCode'), $payload->nullableInteger('quantityPrecision'),
            $payload->nullableInteger('pricePrecision'), self::confirmDuplicate($body, $payload),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function update(array $body): UpdateInstrumentInput
    {
        $payload = JsonPayload::of($body, [...self::REQUIRED, 'version'], self::OPTIONAL);

        return new UpdateInstrumentInput(
            $payload->string('name'), $payload->nullableString('isin'), $payload->nullableString('ticker'),
            $payload->nullableString('venueMic'), $payload->string('instrumentType'), $payload->nullableString('assetClass'),
            $payload->nullableString('region'), $payload->string('currencyCode'), $payload->nullableInteger('quantityPrecision'),
            $payload->nullableInteger('pricePrecision'), self::confirmDuplicate($body, $payload), $payload->integer('version'),
        );
    }

    /**
     * The body of a deactivation or reactivation.
     *
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function version(array $body): int
    {
        return JsonPayload::of($body, ['version'])->integer('version');
    }

    /** @param array<mixed> $body */
    private static function confirmDuplicate(array $body, JsonPayload $payload): bool
    {
        return array_key_exists('confirmDuplicate', $body) && $payload->boolean('confirmDuplicate');
    }
}

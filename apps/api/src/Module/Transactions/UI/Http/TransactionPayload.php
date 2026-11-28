<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;

/** The split rows of a transaction body, each narrowed to its exact field set. */
final class TransactionPayload
{
    private const array SPLIT_FIELDS = ['amount', 'analyticAxes', 'categoryId', 'note'];

    /**
     * A split row list. `null` means "use the categoryId shorthand instead";
     * an empty list is a valid, deliberate allocation clear.
     *
     * @return ?list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}>
     */
    public static function nullableSplitRows(JsonPayload $payload, string $field): ?array
    {
        return null === $payload->raw($field) ? null : self::splitRows($payload, $field);
    }

    /** @return list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}> */
    public static function splitRows(JsonPayload $payload, string $field): array
    {
        // The row count is bounded by the domain, and by the body ceiling before it.
        return array_map(static fn (JsonPayload $row): array => [
            'categoryId' => $row->uuid('categoryId'),
            'amount' => $row->amount('amount'),
            'analyticAxes' => null === $row->raw('analyticAxes') ? null : $row->stringList('analyticAxes', \PHP_INT_MAX),
            'note' => $row->nullableString('note'),
        ], $payload->objects($field, self::SPLIT_FIELDS, \PHP_INT_MAX));
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Domain\Uuid;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Transactions\Application\Categorization\CategorizationRuleInput;

final class CategorizationRulePayload
{
    public static function input(JsonPayload $payload, bool $updating): CategorizationRuleInput
    {
        return new CategorizationRuleInput(
            $payload->string('label'), $payload->integer('priority'), self::identifiers($payload, 'accountScope'),
            self::object($payload, 'conditions'), $payload->uuid('targetCategoryId'), $payload->stringList('targetAxes', \PHP_INT_MAX),
            $payload->nullableString('targetCounterparty'), $payload->string('effectiveFrom'), $payload->nullableString('effectiveTo'),
            $updating ? $payload->boolean('active') : true, $updating ? $payload->integer('version') : null,
        );
    }

    /** @return array<mixed> */
    private static function object(JsonPayload $payload, string $field): array
    {
        $value = $payload->raw($field);
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new MalformedPayload(sprintf('%s must be an object.', $field));
        }

        return $value;
    }

    /**
     * Checked here because a malformed identifier would otherwise reach the
     * database as a failed uuid cast instead of an unprocessable payload.
     *
     * @return list<string>
     */
    private static function identifiers(JsonPayload $payload, string $field): array
    {
        $identifiers = $payload->stringList($field, \PHP_INT_MAX);
        if (!array_all($identifiers, Uuid::isValid(...))) {
            throw new MalformedPayload(sprintf('%s must be a list of canonical UUIDs.', $field));
        }

        return $identifiers;
    }
}

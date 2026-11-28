<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use App\Module\Foundation\Domain\Uuid;

/**
 * A decoded request body, narrowed field by field.
 *
 * The contract is exact rather than tolerant: every required field is present
 * and no field outside the declared set is accepted. Refusing an unknown field
 * is what stops a client from reaching an attribute the endpoint never meant to
 * expose, and refusing a missing one stops a partial body from silently
 * blanking a value the client did not mean to clear.
 *
 * Every accessor asserts the JSON type it needs instead of coercing, so `"1"`
 * never becomes a version, `"true"` never becomes a policy and a decimal never
 * arrives as a JSON number a binary float has already rounded.
 */
final readonly class JsonPayload
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param array<mixed> $body     the decoded JSON body
     * @param list<string> $required the fields the endpoint declares
     * @param list<string> $optional fields that may be left out of the body
     *
     * @throws MalformedPayload when a key is missing, unknown or not a string
     */
    public static function of(array $body, array $required, array $optional = []): self
    {
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new MalformedPayload('A field name must be a string.');
            }

            $fields[$key] = $value;
        }

        $submitted = array_keys($fields);
        if ([] !== array_diff($required, $submitted) || [] !== array_diff($submitted, $required, $optional)) {
            throw new MalformedPayload('A body carries exactly its declared fields.');
        }

        return new self($fields);
    }

    /** The undecided value of a field, `null` when it is absent. */
    public function raw(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function string(string $field): string
    {
        $value = $this->raw($field);
        if (!is_string($value)) {
            throw new MalformedPayload(sprintf('%s must be a string.', $field));
        }

        return $value;
    }

    public function nullableString(string $field): ?string
    {
        return null === $this->raw($field) ? null : $this->string($field);
    }

    public function boolean(string $field): bool
    {
        $value = $this->raw($field);
        if (!is_bool($value)) {
            throw new MalformedPayload(sprintf('%s must be a boolean.', $field));
        }

        return $value;
    }

    public function integer(string $field): int
    {
        $value = $this->raw($field);
        if (!is_int($value)) {
            throw new MalformedPayload(sprintf('%s must be an integer.', $field));
        }

        return $value;
    }

    public function nullableInteger(string $field): ?int
    {
        return null === $this->raw($field) ? null : $this->integer($field);
    }

    public function uuid(string $field): string
    {
        $value = $this->string($field);
        if (!Uuid::isValid($value)) {
            throw new MalformedPayload(sprintf('%s must be a canonical UUID.', $field));
        }

        return $value;
    }

    public function nullableUuid(string $field): ?string
    {
        return null === $this->raw($field) ? null : $this->uuid($field);
    }

    /** @return list<string> */
    public function stringList(string $field, int $maxItems): array
    {
        $values = [];
        foreach ($this->items($field, $maxItems) as $item) {
            if (!is_string($item)) {
                throw new MalformedPayload(sprintf('%s must be a list of strings.', $field));
            }

            $values[] = $item;
        }

        return $values;
    }

    /**
     * A list of nested objects, each narrowed against its own exact field set.
     *
     * @param list<string> $expectedFields
     *
     * @return list<self>
     */
    public function objects(string $field, array $expectedFields, int $maxItems): array
    {
        $objects = [];
        foreach ($this->items($field, $maxItems) as $item) {
            if (!is_array($item)) {
                throw new MalformedPayload(sprintf('%s must be a list of objects.', $field));
            }

            $objects[] = self::of($item, $expectedFields);
        }

        return $objects;
    }

    /** @return array{value: string, assetCode: string} */
    public function amount(string $field): array
    {
        $value = $this->raw($field);
        if (!is_array($value) || array_is_list($value)) {
            throw new MalformedPayload(sprintf('%s must be an object.', $field));
        }

        $amount = self::of($value, ['value', 'assetCode']);

        return ['value' => $amount->string('value'), 'assetCode' => $amount->string('assetCode')];
    }

    /** @return list<mixed> */
    private function items(string $field, int $maxItems): array
    {
        $value = $this->raw($field);
        if (!is_array($value) || !array_is_list($value)) {
            throw new MalformedPayload(sprintf('%s must be a list.', $field));
        }

        // The bound is enforced before anything is parsed, so an oversized body
        // costs one length check rather than a full traversal.
        if (count($value) > $maxItems) {
            throw new MalformedPayload(sprintf('%s carries at most %d entries.', $field, $maxItems));
        }

        return $value;
    }
}

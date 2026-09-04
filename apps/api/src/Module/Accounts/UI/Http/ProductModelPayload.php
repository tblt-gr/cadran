<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

/**
 * A decoded product-model request body, narrowed field by field.
 *
 * The contract is exact rather than tolerant: a body carries the fields its
 * endpoint declares, no more and no fewer, at every level of nesting. Refusing
 * an unknown field is what stops a client from reaching an attribute the
 * endpoint never meant to expose — the model version, its provenance, its
 * workspace — and refusing a missing one stops a partial body from silently
 * clearing a value the client did not mean to clear.
 *
 * Every accessor asserts the JSON type it needs instead of coercing, so `"1"`
 * never becomes a version and a decimal never arrives as a JSON number a
 * binary float has already rounded.
 *
 * @see AccountPayload for the same contract applied to accounts
 */
final readonly class ProductModelPayload
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param array<mixed> $body           the decoded JSON body
     * @param list<string> $expectedFields the exact field set the endpoint declares
     *
     * @throws \UnexpectedValueException when the body is not exactly $expectedFields
     */
    public static function of(array $body, array $expectedFields): self
    {
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A product model field name must be a string.');
            }

            $fields[$key] = $value;
        }

        $submitted = array_keys($fields);
        sort($submitted);
        sort($expectedFields);
        if ($submitted !== $expectedFields) {
            throw new \UnexpectedValueException('A product model body carries exactly its declared fields.');
        }

        return new self($fields);
    }

    public function string(string $field): string
    {
        $value = $this->fields[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a string.', $field));
        }

        return $value;
    }

    public function nullableString(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a string or null.', $field));
        }

        return $value;
    }

    public function integer(string $field): int
    {
        $value = $this->fields[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an integer.', $field));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function strings(string $field, int $maxItems): array
    {
        $values = [];
        foreach ($this->items($field, $maxItems) as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException(sprintf('%s must be a list of strings.', $field));
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
                throw new \UnexpectedValueException(sprintf('%s must be a list of objects.', $field));
            }

            $objects[] = self::of($item, $expectedFields);
        }

        return $objects;
    }

    /**
     * @return list<mixed>
     */
    private function items(string $field, int $maxItems): array
    {
        $value = $this->fields[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a list.', $field));
        }

        // The bound is enforced before anything is parsed, so an oversized body
        // costs one length check rather than a full traversal.
        if (count($value) > $maxItems) {
            throw new \UnexpectedValueException(sprintf('%s carries at most %d entries.', $field, $maxItems));
        }

        return $value;
    }
}

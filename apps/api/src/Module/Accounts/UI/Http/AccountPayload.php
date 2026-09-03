<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

/**
 * A decoded account request body, narrowed field by field.
 *
 * The contract is exact rather than tolerant: a body carries the fields its
 * endpoint declares, no more and no fewer. Refusing an unknown field is what
 * stops a client from reaching an attribute the endpoint never meant to
 * expose — the account currency, its usage marker or its version — and
 * refusing a missing one stops a partial body from silently blanking a value
 * the client did not mean to clear.
 *
 * Every accessor asserts the JSON type it needs instead of coercing, so `"1"`
 * never becomes a version and `"true"` never becomes an inclusion policy. A
 * rejection is an {@see \UnexpectedValueException}, which the controller
 * answers as an unprocessable entity.
 */
final readonly class AccountPayload
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
                throw new \UnexpectedValueException('An account field name must be a string.');
            }

            $fields[$key] = $value;
        }

        $submitted = array_keys($fields);
        sort($submitted);
        sort($expectedFields);
        if ($submitted !== $expectedFields) {
            throw new \UnexpectedValueException('An account body carries exactly its declared fields.');
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

    public function boolean(string $field): bool
    {
        $value = $this->fields[$field] ?? null;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a boolean.', $field));
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
}

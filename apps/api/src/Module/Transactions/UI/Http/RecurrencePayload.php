<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

/** A recurrence body narrowed to its exact, non-coercing public field set. */
final readonly class RecurrencePayload
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $expectedFields
     */
    public static function of(array $body, array $expectedFields): self
    {
        if ([] !== $expectedFields && array_is_list($body)) {
            throw new \UnexpectedValueException('A recurrence body must be an object.');
        }
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A recurrence field name must be a string.');
            }
            $fields[$key] = $value;
        }
        $submitted = array_keys($fields);
        sort($submitted);
        sort($expectedFields);
        if ($submitted !== $expectedFields) {
            throw new \UnexpectedValueException('A recurrence body carries exactly its declared fields.');
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

    public function identifier(string $field): string
    {
        $value = $this->string($field);
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a canonical UUID.', $field));
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

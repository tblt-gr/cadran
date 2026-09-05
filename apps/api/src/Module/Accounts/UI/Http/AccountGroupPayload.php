<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

final readonly class AccountGroupPayload
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
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A group field name must be a string.');
            }

            $fields[$key] = $value;
        }

        $submitted = array_keys($fields);
        sort($submitted);
        sort($expectedFields);
        if ($submitted !== $expectedFields) {
            throw new \UnexpectedValueException('A group body carries exactly its declared fields.');
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
}

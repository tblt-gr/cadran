<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

final readonly class InstrumentPayload
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $required
     * @param list<string> $optional
     */
    public static function of(array $body, array $required, array $optional = []): self
    {
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key) || !in_array($key, [...$required, ...$optional], true)) {
                throw new \UnexpectedValueException('The instrument body carries only declared fields.');
            }
            $fields[$key] = $value;
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $fields)) {
                throw new \UnexpectedValueException(sprintf('%s is required.', $field));
            }
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

    public function nullableInteger(string $field): ?int
    {
        $value = $this->fields[$field] ?? null;
        if (null !== $value && !is_int($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an integer or null.', $field));
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

    public function boolean(string $field, bool $default = false): bool
    {
        if (!array_key_exists($field, $this->fields)) {
            return $default;
        }
        $value = $this->fields[$field];
        if (!is_bool($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a boolean.', $field));
        }

        return $value;
    }
}

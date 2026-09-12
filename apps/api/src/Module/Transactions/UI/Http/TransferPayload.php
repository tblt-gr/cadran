<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

/** A transfer body narrowed to its exact, non-coercing public field set. */
final readonly class TransferPayload
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
                throw new \UnexpectedValueException('A transfer field name must be a string.');
            }
            $fields[$key] = $value;
        }

        $submitted = array_keys($fields);
        sort($submitted);
        sort($expectedFields);
        if ($submitted !== $expectedFields) {
            throw new \UnexpectedValueException('A transfer body carries exactly its declared fields.');
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

    /** @return array{value: string, assetCode: string} */
    public function amount(string $field): array
    {
        return self::amountShape($this->fields[$field] ?? null, $field);
    }

    /** @return array{value: string, assetCode: string}|null */
    public function nullableAmount(string $field): ?array
    {
        $value = $this->fields[$field] ?? null;

        return null === $value ? null : self::amountShape($value, $field);
    }

    /** @return array{value: string, assetCode: string} */
    private static function amountShape(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an object.', $field));
        }
        $submitted = array_keys($value);
        sort($submitted);
        if (['assetCode', 'value'] !== $submitted || !is_string($value['value']) || !is_string($value['assetCode'])) {
            throw new \UnexpectedValueException(sprintf('%s must be an exact decimal amount.', $field));
        }

        return ['value' => $value['value'], 'assetCode' => $value['assetCode']];
    }
}

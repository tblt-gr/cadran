<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

/** A transaction body narrowed to its exact, non-coercing public field set. */
final readonly class TransactionPayload
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
                throw new \UnexpectedValueException('A transaction field name must be a string.');
            }
            $fields[$key] = $value;
        }

        $submitted = array_keys($fields);
        sort($submitted);
        sort($expectedFields);
        if ($submitted !== $expectedFields) {
            throw new \UnexpectedValueException('A transaction body carries exactly its declared fields.');
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

    public function nullableIdentifier(string $field): ?string
    {
        $value = $this->nullableString($field);
        if (null !== $value && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a canonical UUID or null.', $field));
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
        $value = $this->fields[$field] ?? null;

        return self::amountShape($value, $field);
    }

    /**
     * A split row list. `null` means "use the categoryId shorthand instead";
     * an empty list is a valid, deliberate allocation clear.
     *
     * @return ?list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}>
     */
    public function nullableSplitRows(string $field): ?array
    {
        $value = $this->fields[$field] ?? null;

        return null === $value ? null : $this->splitRows($field);
    }

    /** @return list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}> */
    public function splitRows(string $field): array
    {
        $value = $this->fields[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a list.', $field));
        }

        return array_map(static fn (mixed $row): array => self::splitRow($row, $field), $value);
    }

    /** @return array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string} */
    private static function splitRow(mixed $row, string $field): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new \UnexpectedValueException(sprintf('An item of %s must be an object.', $field));
        }
        $submitted = array_keys($row);
        sort($submitted);
        if (['amount', 'analyticAxes', 'categoryId', 'note'] !== $submitted) {
            throw new \UnexpectedValueException(sprintf('An item of %s carries exactly its declared fields.', $field));
        }

        $categoryId = $row['categoryId'];
        if (!is_string($categoryId) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $categoryId)) {
            throw new \UnexpectedValueException('A split categoryId must be a canonical UUID.');
        }
        $rawAxes = $row['analyticAxes'];
        if (null !== $rawAxes
            && (!is_array($rawAxes) || !array_is_list($rawAxes) || array_any($rawAxes, static fn (mixed $axis): bool => !is_string($axis)))) {
            throw new \UnexpectedValueException('A split analyticAxes must be a list of strings or null.');
        }
        /** @var ?list<string> $axes */
        $axes = $rawAxes;
        $note = $row['note'];
        if (null !== $note && !is_string($note)) {
            throw new \UnexpectedValueException('A split note must be a string or null.');
        }

        return [
            'categoryId' => $categoryId,
            'amount' => self::amountShape($row['amount'], 'amount'),
            'analyticAxes' => $axes,
            'note' => $note,
        ];
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

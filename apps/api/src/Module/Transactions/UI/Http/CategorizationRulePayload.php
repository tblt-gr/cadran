<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\Categorization\CategorizationRuleInput;

final readonly class CategorizationRulePayload
{
    private const string IDENTIFIER = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $expected
     */
    public static function of(array $body, array $expected): self
    {
        if (array_is_list($body)) {
            throw new \UnexpectedValueException();
        }
        $fields = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException();
            }
            $fields[$key] = $value;
        }
        $keys = array_keys($fields);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \UnexpectedValueException();
        }

        return new self($fields);
    }

    public function input(bool $updating): CategorizationRuleInput
    {
        return new CategorizationRuleInput(
            $this->string('label'), $this->integer('priority'), $this->identifiers('accountScope'),
            $this->object('conditions'), $this->identifier('targetCategoryId'), $this->strings('targetAxes'),
            $this->nullableString('targetCounterparty'), $this->string('effectiveFrom'), $this->nullableString('effectiveTo'),
            $updating ? $this->boolean('active') : true, $updating ? $this->integer('version') : null,
        );
    }

    public function integer(string $key): int
    {
        $value = $this->fields[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException();
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->fields[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException();
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException();
        }

        return $value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->fields[$key] ?? null;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException();
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(string $key): array
    {
        $value = $this->fields[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException();
        }

        return array_map(static function (mixed $item): string {
            if (!is_string($item)) {
                throw new \UnexpectedValueException();
            }

            return $item;
        }, $value);
    }

    /** @return array<mixed> */
    private function object(string $key): array
    {
        $value = $this->fields[$key] ?? null;
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new \UnexpectedValueException();
        }

        return $value;
    }

    private function identifier(string $key): string
    {
        $value = $this->string($key);
        if (1 !== preg_match(self::IDENTIFIER, $value)) {
            throw new \UnexpectedValueException();
        }

        return $value;
    }

    /**
     * Checked here because a malformed identifier would otherwise reach the
     * database as a failed uuid cast instead of an unprocessable payload.
     *
     * @return list<string>
     */
    private function identifiers(string $key): array
    {
        return array_map(static function (string $item): string {
            if (1 !== preg_match(self::IDENTIFIER, $item)) {
                throw new \UnexpectedValueException();
            }

            return $item;
        }, $this->strings($key));
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

final readonly class RuleTextCondition
{
    public const int MAX_VALUE_LENGTH = 120;

    private function __construct(public RuleTextOperator $operator, public string $value)
    {
    }

    public static function fromDocument(mixed $document, string $field): self
    {
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidCategorizationRule(sprintf('The %s condition must be an object.', $field));
        }
        $keys = array_keys($document);
        sort($keys);
        if (['operator', 'value'] !== $keys || !is_string($document['operator']) || !is_string($document['value'])) {
            throw new InvalidCategorizationRule(sprintf('The %s condition carries exactly an operator and a value.', $field));
        }
        $operator = RuleTextOperator::tryFrom($document['operator'])
            ?? throw new InvalidCategorizationRule(sprintf('The %s operator is unknown.', $field));
        $value = trim($document['value']);
        if (RuleTextOperator::REGEX === $operator) {
            $violation = RegexSafetyPolicy::violation($value);
            if (null !== $violation) {
                throw new UnsafeRulePattern($violation, $field);
            }
        }
        if ('' === $value || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidCategorizationRule(sprintf('The %s value must contain between 1 and %d characters.', $field, self::MAX_VALUE_LENGTH));
        }

        return new self($operator, $value);
    }

    /** @param \Closure(string, string): bool $regex */
    public function matches(?string $subject, \Closure $regex): bool
    {
        if (null === $subject) {
            return false;
        }
        $trimmed = trim($subject);

        return match ($this->operator) {
            RuleTextOperator::EQUALS => mb_strtolower($trimmed) === mb_strtolower($this->value),
            RuleTextOperator::CONTAINS => str_contains(mb_strtolower($trimmed), mb_strtolower($this->value)),
            RuleTextOperator::REGEX => $regex($this->value, $trimmed),
        };
    }

    /** @return array{operator: string, value: string} */
    public function toDocument(): array
    {
        return ['operator' => $this->operator->value, 'value' => $this->value];
    }
}

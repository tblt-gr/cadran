<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

final readonly class RuleTextCondition
{
    public const int MAX_VALUE_LENGTH = 120;

    private function __construct(
        public RuleTextSource $source,
        public RuleTextOperator $operator,
        public string $value,
        public bool $negated,
    ) {
    }

    public static function fromDocument(mixed $document): self
    {
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidCategorizationRule('A text predicate must be an object.');
        }
        $keys = array_keys($document);
        sort($keys);
        if (['negated', 'operator', 'source', 'value'] !== $keys || !is_string($document['source']) || !is_string($document['operator']) || !is_string($document['value']) || !is_bool($document['negated'])) {
            throw new InvalidCategorizationRule('A text predicate carries exactly a source, an operator, a value and a boolean negation.');
        }
        $source = RuleTextSource::tryFrom($document['source'])
            ?? throw new InvalidCategorizationRule('The text predicate source is unknown.');
        $operator = RuleTextOperator::tryFrom($document['operator'])
            ?? throw new InvalidCategorizationRule(sprintf('The %s text predicate operator is unknown.', $source->value));
        $value = trim($document['value']);
        if (RuleTextOperator::REGEX === $operator) {
            $violation = RegexSafetyPolicy::violation($value);
            if (null !== $violation) {
                throw new UnsafeRulePattern($violation, $source->value);
            }
        }
        if ('' === $value || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidCategorizationRule(sprintf('The %s text predicate value must contain between 1 and %d characters.', $source->value, self::MAX_VALUE_LENGTH));
        }

        return new self($source, $operator, $value, $document['negated']);
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

    /** @return array{source: string, operator: string, value: string, negated: bool} */
    public function toDocument(): array
    {
        return ['source' => $this->source->value, 'operator' => $this->operator->value, 'value' => $this->value, 'negated' => $this->negated];
    }
}

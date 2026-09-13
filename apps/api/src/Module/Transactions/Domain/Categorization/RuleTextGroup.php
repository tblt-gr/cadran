<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

final readonly class RuleTextGroup
{
    public const int MAX_PREDICATES = 20;

    /** @param non-empty-list<RuleTextCondition> $predicates */
    private function __construct(public RuleTextCombinator $combinator, public array $predicates)
    {
    }

    public static function fromDocument(mixed $document): self
    {
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidCategorizationRule('The text condition group must be an object.');
        }
        $keys = array_keys($document);
        sort($keys);
        if (['combinator', 'predicates'] !== $keys || !is_string($document['combinator']) || !is_array($document['predicates']) || !array_is_list($document['predicates'])) {
            throw new InvalidCategorizationRule('The text condition group carries exactly a combinator and a predicate list.');
        }
        $combinator = RuleTextCombinator::tryFrom($document['combinator'])
            ?? throw new InvalidCategorizationRule('The text condition combinator must be AND or OR.');
        if ([] === $document['predicates'] || count($document['predicates']) > self::MAX_PREDICATES) {
            throw new InvalidCategorizationRule(sprintf('The text condition group must contain between 1 and %d predicates.', self::MAX_PREDICATES));
        }

        /** @var non-empty-list<RuleTextCondition> $predicates */
        $predicates = array_map(RuleTextCondition::fromDocument(...), $document['predicates']);

        return new self($combinator, $predicates);
    }

    /** @param \Closure(string, string): bool $regex */
    public function matches(CategorizationSubject $subject, \Closure $regex): bool
    {
        foreach ($this->predicates as $predicate) {
            $matches = $predicate->matches($predicate->source->read($subject), $regex);
            $matches = $predicate->negated ? !$matches : $matches;
            if (RuleTextCombinator::AND === $this->combinator && !$matches) {
                return false;
            }
            if (RuleTextCombinator::OR === $this->combinator && $matches) {
                return true;
            }
        }

        return RuleTextCombinator::AND === $this->combinator;
    }

    /** @return array{combinator: string, predicates: non-empty-list<array{source: string, operator: string, value: string, negated: bool}>} */
    public function toDocument(): array
    {
        return [
            'combinator' => $this->combinator->value,
            'predicates' => array_map(static fn (RuleTextCondition $predicate): array => $predicate->toDocument(), $this->predicates),
        ];
    }
}

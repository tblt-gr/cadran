<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

/** A conjunction of optional conditions; at least one member is present. */
final readonly class RuleConditions
{
    private const array MEMBERS = ['rawLabel', 'normalizedLabel', 'counterparty', 'mcc', 'amount', 'direction'];

    private function __construct(
        public ?RuleTextCondition $rawLabel,
        public ?RuleTextCondition $normalizedLabel,
        public ?RuleTextCondition $counterparty,
        public ?string $mcc,
        public ?RuleAmountRange $amount,
        public ?RuleDirection $direction,
    ) {
    }

    /** @param array<mixed> $document */
    public static function fromDocument(array $document): self
    {
        if (array_is_list($document) && [] !== $document) {
            throw new InvalidCategorizationRule('The conditions must be an object.');
        }
        if ([] !== array_diff(array_map(strval(...), array_keys($document)), self::MEMBERS)) {
            throw new InvalidCategorizationRule('The conditions carry an unknown member.');
        }
        $text = static fn (string $field): ?RuleTextCondition => null === ($document[$field] ?? null)
            ? null : RuleTextCondition::fromDocument($document[$field], $field);
        $mcc = $document['mcc'] ?? null;
        if (null !== $mcc && (!is_string($mcc) || 1 !== preg_match('/^[0-9]{4}$/D', $mcc))) {
            throw new InvalidCategorizationRule('The mcc condition must carry exactly four digits.');
        }
        $direction = $document['direction'] ?? null;
        if (null !== $direction && (!is_string($direction) || null === RuleDirection::tryFrom($direction))) {
            throw new InvalidCategorizationRule('The direction condition must be IN or OUT.');
        }

        $conditions = new self(
            $text('rawLabel'),
            $text('normalizedLabel'),
            $text('counterparty'),
            $mcc,
            null === ($document['amount'] ?? null) ? null : RuleAmountRange::fromDocument($document['amount']),
            null === $direction ? null : RuleDirection::from($direction),
        );
        if ([] === array_filter($conditions->toDocument(), static fn (mixed $member): bool => null !== $member)) {
            throw new InvalidCategorizationRule('A rule needs at least one condition.');
        }

        return $conditions;
    }

    /** @param \Closure(string, string): bool $regex evaluates a stored pattern against a trimmed subject */
    public function matches(CategorizationSubject $subject, \Closure $regex): bool
    {
        // Cheap exact checks first, so a pattern only runs on a plausible candidate.
        return (null === $this->mcc || $this->mcc === $subject->mcc)
            && (null === $this->direction || ($subject->amount->value->isNegative() ? RuleDirection::OUT : RuleDirection::IN) === $this->direction)
            && (null === $this->amount || $this->amount->contains($subject->amount))
            && (null === $this->counterparty || $this->counterparty->matches($subject->counterparty, $regex))
            && (null === $this->normalizedLabel || $this->normalizedLabel->matches($subject->normalizedLabel(), $regex))
            && (null === $this->rawLabel || $this->rawLabel->matches($subject->rawLabel, $regex));
    }

    /** @return array{rawLabel: ?array{operator: string, value: string}, normalizedLabel: ?array{operator: string, value: string}, counterparty: ?array{operator: string, value: string}, mcc: ?string, amount: ?array{min: ?string, max: ?string, assetCode: string}, direction: ?string} */
    public function toDocument(): array
    {
        return [
            'rawLabel' => $this->rawLabel?->toDocument(),
            'normalizedLabel' => $this->normalizedLabel?->toDocument(),
            'counterparty' => $this->counterparty?->toDocument(),
            'mcc' => $this->mcc,
            'amount' => $this->amount?->toDocument(),
            'direction' => $this->direction?->value,
        ];
    }
}

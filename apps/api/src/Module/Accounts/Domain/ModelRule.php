<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RuleKind;

/**
 * One dated rule period of a workspace product model.
 *
 * A period carries no publication and no verification trace, unlike a
 * catalogue rule: nobody published it, and presenting a figure the holder
 * typed as sourced would be the lie the catalogue's provenance exists to
 * prevent. What it does carry is its own dates, so a promotional rate that ran
 * for six months keeps saying so once it is over.
 */
final readonly class ModelRule
{
    public function __construct(
        public string $id,
        public RuleKind $kind,
        public DeclaredRuleValue $value,
        public EffectivePeriod $period,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidProductModel('A model rule identifier must be a canonical UUID.');
        }

        if ($kind->valueType() !== $value->type) {
            throw new InvalidProductModel(sprintf('A %s rule carries a %s value.', $kind->value, $kind->valueType()->value));
        }
    }

    /**
     * The same period closed the day before $day. Superseding an open-ended
     * rule records when it stopped applying instead of deleting it, so a past
     * statement still resolves against the rule that covered it.
     */
    public function closedBefore(\DateTimeImmutable $day): self
    {
        return new self(
            $this->id,
            $this->kind,
            $this->value,
            new EffectivePeriod($this->period->validFrom, $day->modify('-1 day')),
        );
    }

    public function copyAs(string $id): self
    {
        return new self($id, $this->kind, $this->value, $this->period);
    }
}

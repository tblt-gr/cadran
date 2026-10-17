<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Amount;

use App\Module\Import\Domain\Formula\FormulaTerm;
use App\Module\Import\Domain\ReasonCode;

/**
 * What an amount cell holds: nothing, a rejection, or movements.
 *
 * `terms` are the non-zero movements in term order; `excluded` carries one
 * `ZERO_AMOUNT` per zero term (reported, never silently dropped).
 * `floatArtifact` is true when a stored value had to be normalized under the
 * binary-float tolerance (a warning, not an error).
 */
final readonly class AmountReading
{
    /**
     * @param list<FormulaTerm> $terms
     * @param list<ReasonCode>  $excluded
     */
    private function __construct(
        public bool $empty,
        public ?ReasonCode $rejection,
        public array $terms,
        public array $excluded,
        public bool $floatArtifact,
    ) {
    }

    public static function empty(): self
    {
        return new self(true, null, [], [], false);
    }

    public static function rejected(ReasonCode $reason): self
    {
        return new self(false, $reason, [], [], false);
    }

    /**
     * @param list<FormulaTerm> $terms
     * @param list<ReasonCode>  $excluded
     */
    public static function movements(array $terms, array $excluded, bool $floatArtifact): self
    {
        return new self(false, null, $terms, $excluded, $floatArtifact);
    }

    public function isRejected(): bool
    {
        return null !== $this->rejection;
    }
}

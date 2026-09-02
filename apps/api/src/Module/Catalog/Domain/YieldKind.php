<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * Where a product's return comes from.
 *
 * This is the enum that keeps a market product from ever showing a promised
 * rate: only a regulated or contractually fixed yield is guaranteed, and only
 * those kinds expect a rate rule in the catalogue. A PEA, a CTO or a unit of
 * account earns whatever its assets earn, which the catalogue cannot know.
 */
enum YieldKind: string
{
    case NONE = 'NONE';
    case REGULATED_RATE = 'REGULATED_RATE';
    case CONTRACTUAL_FIXED = 'CONTRACTUAL_FIXED';
    case CONTRACTUAL_VARIABLE = 'CONTRACTUAL_VARIABLE';
    case MARKET = 'MARKET';
    case MANUAL_VALUATION = 'MANUAL_VALUATION';

    /**
     * Whether the rate the catalogue carries is owed to the holder. A variable
     * contractual rate is published but revisable, so it is not guaranteed
     * forward and is never displayed as an acquired return.
     */
    public function isGuaranteed(): bool
    {
        return match ($this) {
            self::REGULATED_RATE, self::CONTRACTUAL_FIXED => true,
            default => false,
        };
    }

    /**
     * The rule kinds a product of this yield is expected to carry. A kind
     * listed here with no rule effective on the business date is reported as
     * unavailable, never as zero.
     *
     * @return list<RuleKind>
     */
    public function expectedRuleKinds(): array
    {
        return match ($this) {
            self::REGULATED_RATE, self::CONTRACTUAL_FIXED, self::CONTRACTUAL_VARIABLE => [RuleKind::ANNUAL_RATE],
            default => [],
        };
    }

    /**
     * Whether a rate rule may be attached to a product of this yield at all.
     * Attaching one to a market product would turn an assumption into a
     * promise, which the catalogue must refuse rather than display carefully.
     */
    public function acceptsRateRule(): bool
    {
        return [] !== $this->expectedRuleKinds();
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RuleKind;

/**
 * What a workspace product model says on one business date.
 *
 * The counterpart of {@see \App\Module\Catalog\Domain\EffectiveProduct} for a
 * model instead of a catalogue product. `unavailableRuleKinds` is the reason a
 * screen shows a dash rather than a figure: the model's envelope or yield is
 * expected to carry that rule, and no recorded period covers the date asked
 * for. It is never rendered as zero.
 */
final readonly class EffectiveModel
{
    /**
     * @param list<ModelRule> $rules
     * @param list<RuleKind>  $unavailableRuleKinds
     */
    public function __construct(
        public ProductModel $model,
        public \DateTimeImmutable $asOf,
        public array $rules,
        public array $unavailableRuleKinds,
    ) {
    }
}

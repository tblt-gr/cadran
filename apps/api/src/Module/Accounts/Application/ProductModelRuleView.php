<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ModelRule;

/**
 * One dated period of a model as a client reads it.
 *
 * `validTo` is null when the period is in force with no known end. That is not
 * a missing value and not an expiry: a client renders it as still applying,
 * never as a dash and never as zero.
 */
final readonly class ProductModelRuleView
{
    /**
     * @param list<ProductModelRateBracketView> $brackets
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $valueType,
        public ?string $amount,
        public ?string $amountAssetCode,
        public ?string $text,
        public ?string $rateApplication,
        public array $brackets,
        public string $validFrom,
        public ?string $validTo,
    ) {
    }

    public static function of(ModelRule $rule): self
    {
        $amount = $rule->value->amount;
        $scale = $rule->value->scale;

        return new self(
            id: $rule->id,
            kind: $rule->kind->value,
            valueType: $rule->value->type->value,
            amount: $amount?->value->toString(),
            amountAssetCode: $amount?->asset->toString(),
            text: $rule->value->text,
            rateApplication: $scale?->application->value,
            brackets: null === $scale ? [] : array_map(ProductModelRateBracketView::of(...), $scale->brackets),
            validFrom: $rule->period->validFrom->format('Y-m-d'),
            validTo: $rule->period->validTo?->format('Y-m-d'),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ModelRule;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Reference\Application\AssetCatalog;

/**
 * Turns a submitted period into a recorded one: parsed, given an identity, and
 * checked against the asset reference.
 *
 * Creating a model and revising one both go through here, so a ceiling
 * denominated in an unknown unit is refused the same way on both paths rather
 * than reaching the database and coming back as a foreign-key error.
 */
final readonly class SubmittedModelRule
{
    public function __construct(
        private UuidGenerator $uuidGenerator,
        private AssetCatalog $assets,
    ) {
    }

    public function toRule(ModelRuleInput $input): ModelRule
    {
        $rule = ProductModelInputParser::rule($input, $this->uuidGenerator->generate());

        $amount = $rule->value->amount;
        // The asset reference is global and read-only, so an unknown code is a
        // client error rather than something the workspace could create.
        if (RuleValueType::AMOUNT === $rule->value->type && null !== $amount && null === $this->assets->findByCode($amount->asset)) {
            throw new InvalidProductModelInput('The amount asset must exist in the system reference.');
        }

        return $rule;
    }
}

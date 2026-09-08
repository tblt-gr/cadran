<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ModelRule;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Domain\UuidGenerator;

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
        private AmountInputParser $amounts,
    ) {
    }

    public function toRule(DeclaredRuleInput $input): ModelRule
    {
        return ProductModelInputParser::rule($input, $this->uuidGenerator->generate(), $this->amounts);
    }
}

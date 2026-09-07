<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRuleAuthority;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\InvalidAccountRuleOverride;
use App\Module\Accounts\Domain\InvalidDeclaredRule;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Domain\UuidGenerator;

/**
 * Turns a submitted override into a recorded one: parsed, checked against the
 * authority the account follows, given an identity and an author.
 *
 * The authority check is the point of this class. An override is the one place
 * a holder writes a figure that will sit beside a published one, so it must
 * pass exactly the tests the authority itself passed: no rate on a product
 * that promises none, no ceiling on a product that tracks no balance, no
 * amount in a unit the account is not held in.
 */
final readonly class SubmittedAccountRuleOverride
{
    public function __construct(
        private UuidGenerator $uuidGenerator,
        private AmountInputParser $amounts,
    ) {
    }

    public function toOverride(
        Account $account,
        AccountRuleAuthority $authority,
        AccountRuleOverrideInput $input,
        string $authorId,
        \DateTimeImmutable $recordedAt,
    ): AccountRuleOverride {
        try {
            $kind = DeclaredRuleParser::kind($input->rule->kind);
            $authority->assertMayState($kind);

            $value = DeclaredRuleParser::value($kind, $input->rule, $this->amounts);
            if (RuleValueType::AMOUNT === $value->type) {
                $amount = $value->amount ?? throw new InvalidAccountRuleOverrideInput('A ceiling override carries an amount.');
                $authority->assertDenominatedIn($amount, $account->assetCode);
            }

            return new AccountRuleOverride(
                id: $this->uuidGenerator->generate(),
                workspace: $account->workspace,
                accountId: $account->id,
                kind: $kind,
                value: $value,
                period: DeclaredRuleParser::period($input->rule),
                reason: $input->reason,
                authorId: $authorId,
                recordedAt: $recordedAt,
            );
        } catch (InvalidDeclaredRuleInput|InvalidDeclaredRule|InvalidAccountRuleOverride $failure) {
            throw new InvalidAccountRuleOverrideInput($failure->getMessage(), previous: $failure);
        }
    }
}

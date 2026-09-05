<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * An override as it arrives from a client: the dated rule itself, and the
 * reason it is being recorded against this account rather than inherited.
 *
 * The author is deliberately absent. It is read from the authenticated
 * session, never from the body, so no request can attribute a local claim to
 * somebody else.
 */
final readonly class AccountRuleOverrideInput
{
    public function __construct(
        public DeclaredRuleInput $rule,
        public string $reason,
    ) {
    }
}

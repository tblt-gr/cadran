<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountRuleOverrideConflict;
use App\Module\Accounts\Application\AccountRuleOverrideNotFound;
use App\Module\Accounts\Application\InvalidAccountInput;
use App\Module\Accounts\Application\InvalidAccountRuleOverrideInput;
use App\Module\Accounts\Application\InvalidDeclaredRuleInput;
use App\Module\Accounts\Application\StaleAccountOrder;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Domain\InvalidAccountMove;
use App\Module\Accounts\UI\Http\AccountProblems;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;

final class AccountProblemsTest extends ProblemTableTestCase
{
    public static function tables(): iterable
    {
        $forbidden = [new WorkspaceAccessDenied(), 403, 'api.problem.account_forbidden'];
        $notFound = [new AccountNotFound(), 404, 'api.problem.account_not_found'];
        $archived = [new AccountArchived(), 422, 'api.problem.account_archived', '/problems/account-archived'];
        $invalid = [new InvalidAccountInput(), 422, 'api.problem.invalid_account'];
        $malformed = [new MalformedPayload(), 422, 'api.problem.invalid_account'];
        $stale = [new StaleAccountVersion(), 409, 'api.problem.account_stale_version', '/problems/stale-version'];
        $labelTaken = [new AccountConflict(), 409, 'api.problem.account_label_taken', '/problems/account-label-taken'];
        $invalidOverride = 'api.problem.invalid_account_rule_override';
        $overrideConflict = [new AccountRuleOverrideConflict(), 409, 'api.problem.account_rule_override_conflict', '/problems/account-rule-override-conflict'];

        yield 'list' => [AccountProblems::list(), [
            [new InvalidAccountInput(), 400, 'api.problem.invalid_account_query'],
            $forbidden,
        ]];
        yield 'show' => [AccountProblems::show(), [$notFound, $forbidden]];
        yield 'rules' => [AccountProblems::rules(), [
            [new InvalidAccountInput(), 400, 'api.problem.invalid_account_rules_query'],
            $notFound,
            $forbidden,
        ]];
        yield 'create' => [AccountProblems::write(creating: true), [$archived, $invalid, $malformed, $labelTaken, $forbidden]];
        yield 'update' => [AccountProblems::write(), [$archived, $invalid, $malformed, $notFound, $stale, $labelTaken, $forbidden]];
        yield 'archive' => [AccountProblems::archive(), [$archived, $invalid, $malformed, $notFound, $stale, $forbidden]];
        yield 'move' => [AccountProblems::move(), [
            [new MalformedPayload(), 422, 'api.problem.invalid_account_move'],
            [new InvalidAccountMove(), 422, 'api.problem.invalid_account_move'],
            $notFound,
            [new StaleAccountOrder(), 409, 'api.problem.account_stale_order', '/problems/stale-account-order'],
            $forbidden,
        ]];
        yield 'rule overrides' => [AccountProblems::ruleOverrides(), [
            $archived,
            [new InvalidAccountRuleOverrideInput(), 422, $invalidOverride],
            [new InvalidDeclaredRuleInput(), 422, $invalidOverride],
            [new MalformedPayload(), 422, $invalidOverride],
            $notFound,
            $overrideConflict,
            $forbidden,
        ]];
        yield 'withdraw rule override' => [AccountProblems::withdrawRuleOverride(), [
            $archived,
            [new MalformedPayload(), 422, $invalidOverride],
            [new AccountNotFound(), 404, 'api.problem.account_rule_override_not_found'],
            [new AccountRuleOverrideNotFound(), 404, 'api.problem.account_rule_override_not_found'],
            $overrideConflict,
            $forbidden,
        ]];
    }
}

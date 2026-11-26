<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountOverviewCompositionTooLarge;
use App\Module\Accounts\Application\GroupCompositionTooLarge;
use App\Module\Accounts\Application\InvalidAccountOverviewQuery;
use App\Module\Accounts\Application\InvalidNetWorthQuery;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\StaleAccountOrder;
use App\Module\Accounts\UI\Http\AccountOverviewProblems;
use App\Module\Foundation\Application\WorkspaceAccessDenied;

final class AccountOverviewProblemsTest extends ProblemTableTestCase
{
    public static function tables(): iterable
    {
        $accountForbidden = [new WorkspaceAccessDenied(), 403, 'api.problem.account_forbidden'];
        $invalidQuery = [new InvalidAccountOverviewQuery(), 400, 'api.problem.invalid_account_overview_query'];
        $scopeTooLarge = [new NetWorthScopeTooLarge(), 422, 'api.problem.net_worth_scope_too_large', '/problems/net-worth-scope-too-large'];
        $netWorth = [
            [new InvalidNetWorthQuery(), 400, 'api.problem.invalid_net_worth_query'],
            $scopeTooLarge,
            [new WorkspaceAccessDenied(), 403, 'api.problem.net_worth_forbidden'],
        ];

        yield 'overview' => [AccountOverviewProblems::overview(), [$invalidQuery, $scopeTooLarge, $accountForbidden]];
        yield 'rows' => [AccountOverviewProblems::rows(), [
            $invalidQuery,
            [new AccountNotFound(), 404, 'api.problem.account_not_found'],
            [new StaleAccountOrder(), 409, 'api.problem.account_stale_order', '/problems/stale-account-order'],
            $scopeTooLarge,
            $accountForbidden,
        ]];
        yield 'composition' => [AccountOverviewProblems::composition(), [
            $invalidQuery,
            [new AccountOverviewCompositionTooLarge(), 422, 'api.problem.overview_composition_too_large', '/problems/composition-too-large'],
            $scopeTooLarge,
            $accountForbidden,
        ]];
        yield 'group composition' => [AccountOverviewProblems::groupComposition(), [
            [new AccountGroupNotFound(), 404, 'api.problem.account_group_not_found'],
            $invalidQuery,
            [new GroupCompositionTooLarge(), 422, 'api.problem.composition_too_large', '/problems/composition-too-large'],
            $scopeTooLarge,
            [new WorkspaceAccessDenied(), 403, 'api.problem.account_group_forbidden'],
        ]];
        yield 'net worth read' => [AccountOverviewProblems::netWorthRead(), $netWorth];
        yield 'net worth history' => [AccountOverviewProblems::netWorthHistory(), $netWorth];
    }
}

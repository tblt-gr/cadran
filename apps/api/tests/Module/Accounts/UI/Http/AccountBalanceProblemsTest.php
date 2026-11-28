<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountBalanceNotFound;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountBalanceInput;
use App\Module\Accounts\Application\PortfolioAccountValuedByPortfolio;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\UI\Http\AccountBalanceProblems;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;

final class AccountBalanceProblemsTest extends ProblemTableTestCase
{
    public static function tables(): iterable
    {
        $forbidden = [new WorkspaceAccessDenied(), 403, 'api.problem.account_forbidden'];
        $accountNotFound = [new AccountNotFound(), 404, 'api.problem.account_not_found'];
        $archived = [new AccountArchived(), 422, 'api.problem.account_archived', '/problems/account-archived'];
        $valuedByPortfolio = [
            new PortfolioAccountValuedByPortfolio(), 422, 'api.problem.portfolio_account_valued_by_portfolio',
            '/problems/portfolio-account-valued-by-portfolio', ['code' => 'portfolio_account_valued_by_portfolio'],
        ];
        $invalid = [new InvalidAccountBalanceInput(), 422, 'api.problem.invalid_account_balance'];
        $malformed = [new MalformedPayload(), 422, 'api.problem.invalid_account_balance'];
        $stale = [new StaleAccountVersion(), 409, 'api.problem.account_balance_stale_version', '/problems/stale-version'];
        $conflict = [new AccountBalanceConflict(), 409, 'api.problem.account_balance_conflict', '/problems/account-balance-conflict'];
        $snapshotAccountMissing = [new AccountNotFound(), 404, 'api.problem.account_balance_not_found'];
        $snapshotMissing = [new AccountBalanceNotFound(), 404, 'api.problem.account_balance_not_found'];

        yield 'valuation' => [AccountBalanceProblems::valuation(), [
            [new InvalidAccountBalanceInput(), 400, 'api.problem.invalid_account_valuation_query'],
            $accountNotFound,
            $forbidden,
        ]];
        yield 'history' => [AccountBalanceProblems::history(), [
            [new InvalidAccountBalanceInput(), 422, 'api.problem.invalid_account_valuation_history_query'],
            $accountNotFound,
            $forbidden,
        ]];
        yield 'list' => [AccountBalanceProblems::list(), [
            [new InvalidAccountBalanceInput(), 400, 'api.problem.invalid_account_balance_query'],
            $accountNotFound,
            $forbidden,
        ]];
        yield 'record' => [AccountBalanceProblems::record(), [
            $archived, $valuedByPortfolio, $invalid, $malformed, $accountNotFound, $stale, $conflict, $forbidden,
        ]];
        yield 'correct' => [AccountBalanceProblems::correct(), [
            $archived, $valuedByPortfolio, $invalid, $malformed, $snapshotAccountMissing, $snapshotMissing, $stale, $conflict, $forbidden,
        ]];
        yield 'withdraw' => [AccountBalanceProblems::withdraw(), [
            $archived, $invalid, $malformed, $snapshotAccountMissing, $snapshotMissing, $stale, $conflict, $forbidden,
        ]];
    }
}

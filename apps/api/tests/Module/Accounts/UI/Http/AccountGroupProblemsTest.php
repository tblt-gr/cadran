<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupArchived;
use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Application\InvalidAccountGroupMove;
use App\Module\Accounts\Application\StaleAccountGroupOrder;
use App\Module\Accounts\Application\StaleAccountGroupVersion;
use App\Module\Accounts\UI\Http\AccountGroupProblems;
use App\Module\Foundation\Application\WorkspaceAccessDenied;

final class AccountGroupProblemsTest extends ProblemTableTestCase
{
    public static function tables(): iterable
    {
        $forbidden = [new WorkspaceAccessDenied(), 403, 'api.problem.account_group_forbidden'];
        $notFound = [new AccountGroupNotFound(), 404, 'api.problem.account_group_not_found'];
        $archived = [new AccountGroupArchived(), 422, 'api.problem.account_group_archived', '/problems/account-group-archived'];
        $invalid = [new InvalidAccountGroupInput(), 422, 'api.problem.invalid_account_group'];
        $malformed = [new \UnexpectedValueException(), 422, 'api.problem.invalid_account_group'];
        $stale = [new StaleAccountGroupVersion(), 409, 'api.problem.account_group_stale_version', '/problems/stale-version'];

        yield 'list' => [AccountGroupProblems::list(), [
            [new InvalidAccountGroupInput(), 400, 'api.problem.invalid_account_group_query'],
            $forbidden,
        ]];
        yield 'write' => [AccountGroupProblems::write(), [
            $archived, $invalid, $malformed, $notFound, $stale,
            [new AccountGroupConflict(), 409, 'api.problem.account_group_label_taken', '/problems/account-group-label-taken'],
            $forbidden,
        ]];
        yield 'archive' => [AccountGroupProblems::archive(), [$archived, $invalid, $malformed, $notFound, $stale, $forbidden]];
        yield 'move' => [AccountGroupProblems::move(), [
            $archived,
            [new InvalidAccountGroupMove('anchor'), 422, 'api.problem.account_group_invalid_anchor', '/problems/invalid-account-group-anchor'],
            [new InvalidAccountGroupMove('other', 'other'), 422, 'api.problem.invalid_account_group_move'],
            [new \UnexpectedValueException(), 422, 'api.problem.invalid_account_group_move'],
            $notFound,
            [new StaleAccountGroupOrder(), 409, 'api.problem.account_group_stale_order', '/problems/stale-account-group-order'],
            $forbidden,
        ]];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InvalidPeriodClosureInput;
use App\Module\Accounts\Application\PeriodClosingBlocked;
use App\Module\Accounts\Application\PeriodClosureConflict;
use App\Module\Accounts\Application\PeriodClosureForbidden;
use App\Module\Accounts\Application\PeriodClosureNotFound;
use App\Module\Accounts\Application\StalePeriodClosureVersion;
use App\Module\Accounts\UI\Http\PeriodClosureProblems;
use App\Module\Foundation\Application\WorkspaceAccessDenied;

final class PeriodClosureProblemsTest extends ProblemTableTestCase
{
    public static function tables(): iterable
    {
        $noWorkspace = [new WorkspaceAccessDenied(), 403, 'api.problem.period_closure_no_workspace'];
        $invalid = [new InvalidPeriodClosureInput(), 422, 'api.problem.invalid_period_closure'];
        $conflict = [new PeriodClosureConflict(), 409, 'api.problem.period_closure_conflict', '/problems/period-closure-conflict'];
        $forbidden = [new PeriodClosureForbidden(), 403, 'api.problem.period_closure_forbidden'];

        yield 'list' => [PeriodClosureProblems::list(), [
            [new InvalidPeriodClosureInput(), 400, 'api.problem.invalid_period_closure_query'],
            $noWorkspace,
        ]];
        yield 'read' => [PeriodClosureProblems::read(), [
            [new InvalidPeriodClosureInput(), 404, 'api.problem.not_found'],
            $noWorkspace,
        ]];
        yield 'close' => [PeriodClosureProblems::close(), [
            [new PeriodClosingBlocked([]), 409, 'api.problem.period_closing_blocked', '/problems/period-closing-blocked', ['blockers' => []]],
            $invalid,
            $conflict,
            $forbidden,
            $noWorkspace,
        ]];
        yield 'reopen' => [PeriodClosureProblems::reopen(), [
            $invalid,
            [new PeriodClosureNotFound(), 404, 'api.problem.period_closure_not_found'],
            [new StalePeriodClosureVersion(), 409, 'api.problem.period_closure_stale_version', '/problems/stale-version'],
            $conflict,
            $forbidden,
            $noWorkspace,
        ]];
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InvalidPeriodClosureInput;
use App\Module\Accounts\Application\PeriodClosingBlocked;
use App\Module\Accounts\Application\PeriodClosureConflict;
use App\Module\Accounts\Application\PeriodClosureForbidden;
use App\Module\Accounts\Application\PeriodClosureNotFound;
use App\Module\Accounts\Application\StalePeriodClosureVersion;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ProblemRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the monthly closing endpoints becomes.
 *
 * The first matching row wins, so a subclass precedes its parent: `StalePeriodClosureVersion`
 * before `PeriodClosureConflict`.
 */
final class PeriodClosureProblems
{
    public const string TYPE_BLOCKED = '/problems/period-closing-blocked';
    public const string TYPE_CONFLICT = '/problems/period-closure-conflict';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';

    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [
            InvalidPeriodClosureInput::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_period_closure_query'),
            WorkspaceAccessDenied::class => self::noWorkspace(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function read(): array
    {
        return [
            InvalidPeriodClosureInput::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.not_found'),
            WorkspaceAccessDenied::class => self::noWorkspace(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function close(): array
    {
        return [
            PeriodClosingBlocked::class => static fn (PeriodClosingBlocked $e): ProblemRule => new ProblemRule(
                Response::HTTP_CONFLICT,
                'api.problem.period_closing_blocked',
                self::TYPE_BLOCKED,
                ['blockers' => PeriodClosureRepresentation::blockers($e->blockers)],
            ),
            InvalidPeriodClosureInput::class => self::invalid(),
            PeriodClosureConflict::class => self::conflict(),
            PeriodClosureForbidden::class => self::forbidden(),
            WorkspaceAccessDenied::class => self::noWorkspace(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function reopen(): array
    {
        return [
            InvalidPeriodClosureInput::class => self::invalid(),
            PeriodClosureNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.period_closure_not_found'),
            StalePeriodClosureVersion::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.period_closure_stale_version', self::TYPE_STALE_VERSION),
            PeriodClosureConflict::class => self::conflict(),
            PeriodClosureForbidden::class => self::forbidden(),
            WorkspaceAccessDenied::class => self::noWorkspace(),
        ];
    }

    private static function invalid(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
    }

    private static function conflict(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.period_closure_conflict', self::TYPE_CONFLICT);
    }

    private static function forbidden(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_forbidden');
    }

    private static function noWorkspace(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_no_workspace');
    }
}

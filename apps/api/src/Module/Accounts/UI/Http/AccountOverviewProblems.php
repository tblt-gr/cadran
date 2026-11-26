<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountOverviewCompositionTooLarge;
use App\Module\Accounts\Application\GroupCompositionTooLarge;
use App\Module\Accounts\Application\InvalidAccountOverviewQuery;
use App\Module\Accounts\Application\InvalidNetWorthQuery;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\StaleAccountOrder;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ProblemRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the overview, composition and net worth reads becomes.
 *
 * The first matching row wins, so a subclass must precede its parent wherever both are listed.
 */
final class AccountOverviewProblems
{
    public const string TYPE_STALE_ORDER = '/problems/stale-account-order';
    public const string TYPE_SCOPE_TOO_LARGE = '/problems/net-worth-scope-too-large';
    public const string TYPE_TOO_LARGE = '/problems/composition-too-large';

    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function overview(): array
    {
        return [
            InvalidAccountOverviewQuery::class => self::invalidQuery(),
            NetWorthScopeTooLarge::class => self::scopeTooLarge(),
            WorkspaceAccessDenied::class => self::forbidden('api.problem.account_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function rows(): array
    {
        return [
            InvalidAccountOverviewQuery::class => self::invalidQuery(),
            AccountNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found'),
            StaleAccountOrder::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_stale_order', self::TYPE_STALE_ORDER),
            NetWorthScopeTooLarge::class => self::scopeTooLarge(),
            WorkspaceAccessDenied::class => self::forbidden('api.problem.account_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function composition(): array
    {
        return [
            InvalidAccountOverviewQuery::class => self::invalidQuery(),
            AccountOverviewCompositionTooLarge::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.overview_composition_too_large', self::TYPE_TOO_LARGE),
            NetWorthScopeTooLarge::class => self::scopeTooLarge(),
            WorkspaceAccessDenied::class => self::forbidden('api.problem.account_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function groupComposition(): array
    {
        return [
            AccountGroupNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found'),
            InvalidAccountOverviewQuery::class => self::invalidQuery(),
            GroupCompositionTooLarge::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.composition_too_large', self::TYPE_TOO_LARGE),
            NetWorthScopeTooLarge::class => self::scopeTooLarge(),
            WorkspaceAccessDenied::class => self::forbidden('api.problem.account_group_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function netWorthRead(): array
    {
        return [
            InvalidNetWorthQuery::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query'),
            NetWorthScopeTooLarge::class => self::scopeTooLarge(),
            WorkspaceAccessDenied::class => self::forbidden('api.problem.net_worth_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function netWorthHistory(): array
    {
        return self::netWorthRead();
    }

    private static function invalidQuery(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
    }

    private static function scopeTooLarge(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.net_worth_scope_too_large', self::TYPE_SCOPE_TOO_LARGE);
    }

    private static function forbidden(string $translationKey): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, $translationKey);
    }
}

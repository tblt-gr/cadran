<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountBalanceNotFound;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountBalanceInput;
use App\Module\Accounts\Application\PortfolioAccountValuedByPortfolio;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the valuation and balance snapshot endpoints becomes.
 *
 * The first matching row wins, so a subclass precedes its parent wherever both are listed.
 *
 * The as-of valuation query answers 400 and the valuation history range 422 on purpose, like
 * the portfolio pair: a malformed single date is a bad request, an impossible range is
 * unprocessable. The difference is not drift.
 */
final class AccountBalanceProblems
{
    public const string TYPE_SNAPSHOT_CONFLICT = '/problems/account-balance-conflict';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-archived';

    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function valuation(): array
    {
        return self::read(new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_valuation_query'));
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function history(): array
    {
        return self::read(new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_valuation_history_query'));
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return self::read(new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_balance_query'));
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function record(): array
    {
        return [
            AccountArchived::class => self::archived(),
            PortfolioAccountValuedByPortfolio::class => self::valuedByPortfolio(),
            InvalidAccountBalanceInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            AccountNotFound::class => self::accountNotFound(),
            StaleAccountVersion::class => self::staleVersion(),
            AccountBalanceConflict::class => self::conflict(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function correct(): array
    {
        return [
            AccountArchived::class => self::archived(),
            PortfolioAccountValuedByPortfolio::class => self::valuedByPortfolio(),
            InvalidAccountBalanceInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            AccountNotFound::class => self::snapshotNotFound(),
            AccountBalanceNotFound::class => self::snapshotNotFound(),
            StaleAccountVersion::class => self::staleVersion(),
            AccountBalanceConflict::class => self::conflict(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function withdraw(): array
    {
        return [
            AccountArchived::class => self::archived(),
            InvalidAccountBalanceInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            AccountNotFound::class => self::snapshotNotFound(),
            AccountBalanceNotFound::class => self::snapshotNotFound(),
            StaleAccountVersion::class => self::staleVersion(),
            AccountBalanceConflict::class => self::conflict(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    private static function read(ProblemRule $invalidQuery): array
    {
        return [
            InvalidAccountBalanceInput::class => $invalidQuery,
            AccountNotFound::class => self::accountNotFound(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    private static function archived(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
    }

    private static function valuedByPortfolio(): ProblemRule
    {
        return new ProblemRule(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'api.problem.portfolio_account_valued_by_portfolio',
            '/problems/portfolio-account-valued-by-portfolio',
            ['code' => 'portfolio_account_valued_by_portfolio'],
        );
    }

    private static function invalid(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_balance');
    }

    private static function accountNotFound(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
    }

    private static function snapshotNotFound(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_balance_not_found');
    }

    private static function staleVersion(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_balance_stale_version', self::TYPE_STALE_VERSION);
    }

    private static function conflict(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_balance_conflict', self::TYPE_SNAPSHOT_CONFLICT);
    }

    private static function forbidden(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
    }
}

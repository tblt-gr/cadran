<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupArchived;
use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Application\AccountGroupLimitReached;
use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Application\InvalidAccountGroupMove;
use App\Module\Accounts\Application\StaleAccountGroupOrder;
use App\Module\Accounts\Application\StaleAccountGroupVersion;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the account group endpoints becomes.
 *
 * The first matching row wins, so a subclass must precede its parent; the stale version row
 * stays ahead of the label conflict so that remains true if one ever extends the other.
 */
final class AccountGroupProblems
{
    public const string TYPE_LABEL_TAKEN = '/problems/account-group-label-taken';
    public const string TYPE_LIMIT_REACHED = '/problems/account-group-limit-reached';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-group-archived';
    public const string TYPE_STALE_ORDER = '/problems/stale-account-group-order';
    public const string TYPE_INVALID_ANCHOR = '/problems/invalid-account-group-anchor';

    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [
            InvalidAccountGroupInput::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_group_query'),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /**
     * Creating and updating a group.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function write(): array
    {
        return [
            AccountGroupArchived::class => self::archived(),
            InvalidAccountGroupInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            AccountGroupNotFound::class => self::notFound(),
            StaleAccountGroupVersion::class => self::staleVersion(),
            AccountGroupLimitReached::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_group_limit_reached', self::TYPE_LIMIT_REACHED),
            AccountGroupConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_group_label_taken', self::TYPE_LABEL_TAKEN),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function archive(): array
    {
        return [
            AccountGroupArchived::class => self::archived(),
            InvalidAccountGroupInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            AccountGroupNotFound::class => self::notFound(),
            StaleAccountGroupVersion::class => self::staleVersion(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function move(): array
    {
        return [
            AccountGroupArchived::class => self::archived(),
            InvalidAccountGroupMove::class => static fn (InvalidAccountGroupMove $e): ProblemRule => InvalidAccountGroupMove::INVALID_ANCHOR === $e->reason
                ? new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_group_invalid_anchor', self::TYPE_INVALID_ANCHOR)
                : self::invalidMove(),
            MalformedPayload::class => self::invalidMove(),
            AccountGroupNotFound::class => self::notFound(),
            StaleAccountGroupOrder::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_group_stale_order', self::TYPE_STALE_ORDER),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    private static function archived(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_group_archived', self::TYPE_ARCHIVED);
    }

    private static function invalid(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_group');
    }

    private static function invalidMove(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_group_move');
    }

    private static function notFound(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
    }

    private static function staleVersion(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_group_stale_version', self::TYPE_STALE_VERSION);
    }

    private static function forbidden(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.account_group_forbidden');
    }
}

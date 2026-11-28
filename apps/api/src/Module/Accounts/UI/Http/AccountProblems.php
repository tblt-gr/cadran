<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

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
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the account and rule-override endpoints becomes.
 *
 * The first matching row wins, so a subclass precedes its parent: `AccountArchived` before
 * `InvalidAccountInput`, `StaleAccountVersion` before `AccountConflict`.
 */
final class AccountProblems
{
    /**
     * A label already taken and a stale version are both 409, but they ask the
     * caller for opposite things: pick another label, or reload and reapply.
     * The problem type is what lets a client tell them apart.
     */
    public const string TYPE_LABEL_TAKEN = '/problems/account-label-taken';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-archived';
    public const string TYPE_OVERRIDE_CONFLICT = '/problems/account-rule-override-conflict';

    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [
            InvalidAccountInput::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_query'),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /**
     * Reading an account or its rule overrides.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function show(): array
    {
        return [
            AccountNotFound::class => self::notFound(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function rules(): array
    {
        return [
            InvalidAccountInput::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_rules_query'),
            AccountNotFound::class => self::notFound(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /**
     * Creation names no existing account, so it has neither a not-found nor a stale-version answer.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function write(bool $creating = false): array
    {
        return [
            AccountArchived::class => self::archived(),
            InvalidAccountInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            ...($creating ? [] : [
                AccountNotFound::class => self::notFound(),
                StaleAccountVersion::class => self::staleVersion(),
            ]),
            AccountConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function archive(): array
    {
        return [
            AccountArchived::class => self::archived(),
            InvalidAccountInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            AccountNotFound::class => self::notFound(),
            StaleAccountVersion::class => self::staleVersion(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function move(): array
    {
        $invalidMove = new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_move');

        return [
            MalformedPayload::class => $invalidMove,
            InvalidAccountMove::class => $invalidMove,
            AccountNotFound::class => self::notFound(),
            StaleAccountOrder::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_stale_order', AccountOverviewProblems::TYPE_STALE_ORDER),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /**
     * Recording an override. Listing them submits nothing and answers like {@see self::show()}.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function ruleOverrides(): array
    {
        return [
            AccountArchived::class => self::archived(),
            InvalidAccountRuleOverrideInput::class => self::invalidRuleOverride(),
            InvalidDeclaredRuleInput::class => self::invalidRuleOverride(),
            MalformedPayload::class => self::invalidRuleOverride(),
            AccountNotFound::class => self::notFound(),
            AccountRuleOverrideConflict::class => self::ruleOverrideConflict(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function withdrawRuleOverride(): array
    {
        $overrideNotFound = new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_rule_override_not_found');

        return [
            AccountArchived::class => self::archived(),
            MalformedPayload::class => self::invalidRuleOverride(),
            AccountNotFound::class => $overrideNotFound,
            AccountRuleOverrideNotFound::class => $overrideNotFound,
            AccountRuleOverrideConflict::class => self::ruleOverrideConflict(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    private static function archived(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
    }

    private static function invalid(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
    }

    private static function notFound(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
    }

    private static function staleVersion(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
    }

    private static function invalidRuleOverride(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_rule_override');
    }

    private static function ruleOverrideConflict(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.account_rule_override_conflict', self::TYPE_OVERRIDE_CONFLICT);
    }

    private static function forbidden(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
    }
}

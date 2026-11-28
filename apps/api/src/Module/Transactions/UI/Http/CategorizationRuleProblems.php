<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\Application\Categorization\CategorizationExecutionLimitExceeded;
use App\Module\Transactions\Application\Categorization\CategorizationRangeTooLarge;
use App\Module\Transactions\Application\Categorization\CategorizationRuleNotFound;
use App\Module\Transactions\Application\Categorization\InvalidCategorizationReference;
use App\Module\Transactions\Application\Categorization\InvalidCategorizationRuleInput;
use App\Module\Transactions\Application\Categorization\PreviewStale;
use App\Module\Transactions\Application\Categorization\StaleCategorizationRule;
use App\Module\Transactions\Domain\Categorization\UnsafeRulePattern;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the categorization rule endpoints becomes.
 * Every rule problem shares the transaction wording and differs by its type.
 */
final class CategorizationRuleProblems
{
    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [
            InvalidCategorizationRuleInput::class => self::rule(Response::HTTP_BAD_REQUEST, '/problems/rules.invalid_query'),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function create(): array
    {
        return [
            ...TransactionProblems::idempotency(),
            ...self::unsafePattern(),
            ...self::invalidReference(),
            ...self::executionLimit(),
            ...self::invalid(),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function update(): array
    {
        return [
            ...self::unsafePattern(),
            ...self::invalidReference(),
            ...self::notFound(),
            ...self::stale(),
            ...self::executionLimit(),
            ...self::invalid(),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function archive(): array
    {
        return [
            ...self::notFound(),
            ...self::stale(),
            MalformedPayload::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, '/problems/rules.invalid'),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function preview(): array
    {
        return [
            ...self::notFound(),
            ...self::previewStale(),
            CategorizationRangeTooLarge::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, '/problems/rules.range_too_large'),
            ...self::executionLimit(),
            ...self::invalid(),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function apply(): array
    {
        return [
            ...self::previewStale(),
            ...self::executionLimit(),
            MalformedPayload::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, '/problems/rules.invalid'),
            ...self::forbidden(),
        ];
    }

    /** @param array<string, mixed> $extensions */
    private static function rule(int $status, string $type, array $extensions = []): ProblemRule
    {
        return new ProblemRule($status, 'api.problem.invalid_transaction', $type, $extensions);
    }

    /** @return array<class-string<\Throwable>, \Closure> */
    private static function unsafePattern(): array
    {
        return [
            UnsafeRulePattern::class => static fn (UnsafeRulePattern $e): ProblemRule => self::rule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                '/problems/rules.unsafe_pattern',
                ['reason' => $e->reason, 'field' => $e->field],
            ),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function invalidReference(): array
    {
        return [InvalidCategorizationReference::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, '/problems/rules.invalid_reference')];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function executionLimit(): array
    {
        return [CategorizationExecutionLimitExceeded::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, '/problems/rules.execution_limit')];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function invalid(): array
    {
        $invalid = self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, '/problems/rules.invalid');

        return [InvalidCategorizationRuleInput::class => $invalid, MalformedPayload::class => $invalid];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function notFound(): array
    {
        return [CategorizationRuleNotFound::class => self::rule(Response::HTTP_NOT_FOUND, '/problems/rules.not_found')];
    }

    /** @return array<class-string<\Throwable>, \Closure> */
    private static function stale(): array
    {
        return [
            StaleCategorizationRule::class => static fn (StaleCategorizationRule $e): ProblemRule => self::rule(
                Response::HTTP_CONFLICT,
                'archived' === $e->getMessage() ? '/problems/rules.archived' : '/problems/stale-version',
            ),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function previewStale(): array
    {
        return [PreviewStale::class => self::rule(Response::HTTP_CONFLICT, '/problems/rules.preview_stale')];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function forbidden(): array
    {
        return [WorkspaceAccessDenied::class => self::rule(Response::HTTP_FORBIDDEN, '/problems/rules.forbidden')];
    }
}

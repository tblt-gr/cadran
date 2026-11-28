<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\Application\Recurrence\DuplicateRecurrence;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceInput;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceReference;
use App\Module\Transactions\Application\Recurrence\RecurrenceNotFound;
use App\Module\Transactions\Application\Recurrence\StaleRecurrence;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Response;

/** Which problem document each exception of the recurrence endpoints becomes, one table per action family. */
final class RecurrenceProblems
{
    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function candidates(): array
    {
        return [InvalidRecurrenceInput::class => self::invalidQuery(), ...self::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [InvalidRecurrenceInput::class => self::invalidQuery(), ...self::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function occurrences(): array
    {
        return [
            ...self::notFound(),
            InvalidRecurrenceInput::class => self::invalidQuery(),
            // A repeated query parameter reaches the request reader as an array.
            BadRequestException::class => self::invalidQuery(),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function dismiss(): array
    {
        return [MalformedPayload::class => self::invalid(), ...self::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function restoreCandidate(): array
    {
        return [...self::notFound(), ...self::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function create(): array
    {
        return [
            ...TransactionProblems::idempotency(),
            DuplicateRecurrence::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.recurrence_duplicate', '/problems/recurrences.duplicate'),
            InvalidRecurrenceInput::class => self::invalid(),
            InvalidRecurrenceReference::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            ...self::forbidden(),
        ];
    }

    /**
     * Refreshing the horizon and materializing, which take an empty body.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function run(): array
    {
        return [MalformedPayload::class => self::invalid(), ...self::forbidden()];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function update(): array
    {
        return [
            ...self::notFound(),
            ...self::refusedWhenArchived(),
            InvalidRecurrenceInput::class => self::invalid(),
            InvalidRecurrenceReference::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function archive(): array
    {
        return [
            ...self::notFound(),
            ...self::refusedWhenArchived(),
            InvalidRecurrenceInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function restore(): array
    {
        return [
            ...self::notFound(),
            StaleRecurrence::class => static fn (StaleRecurrence $e): ProblemRule => StaleRecurrence::NOT_ARCHIVED === $e->getMessage()
                ? new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.invalid_recurrence', '/problems/recurrences.not_archived')
                : self::staleVersion(),
            InvalidRecurrenceInput::class => self::invalid(),
            MalformedPayload::class => self::invalid(),
            ...self::forbidden(),
        ];
    }

    private static function invalid(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_recurrence', '/problems/recurrences.invalid');
    }

    private static function invalidQuery(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_recurrence_query', '/problems/recurrences.invalid_query');
    }

    private static function staleVersion(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.recurrence_stale_version', '/problems/stale-version');
    }

    /** @return array<class-string<\Throwable>, \Closure> */
    private static function refusedWhenArchived(): array
    {
        return [
            StaleRecurrence::class => static fn (StaleRecurrence $e): ProblemRule => StaleRecurrence::ARCHIVED === $e->getMessage()
                ? new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.recurrence_archived', '/problems/recurrences.archived')
                : self::staleVersion(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function notFound(): array
    {
        return [RecurrenceNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.recurrence_not_found', '/problems/recurrences.not_found')];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function forbidden(): array
    {
        return [WorkspaceAccessDenied::class => new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.recurrence_forbidden', '/problems/recurrences.forbidden')];
    }
}

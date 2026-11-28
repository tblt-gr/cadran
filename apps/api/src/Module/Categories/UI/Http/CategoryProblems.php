<?php

declare(strict_types=1);

namespace App\Module\Categories\UI\Http;

use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Application\CategoryNotFound;
use App\Module\Categories\Application\CategoryOperationRefused;
use App\Module\Categories\Application\InvalidCategoryInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use Doctrine\DBAL\Exception\RetryableException;
use Symfony\Component\HttpFoundation\Response;

/** Which problem document each exception of the category writes becomes. */
final class CategoryProblems
{
    private function __construct()
    {
    }

    /**
     * Creating and editing a category.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function write(): array
    {
        return [...self::invalid(), ...self::existing(), ...self::forbidden()];
    }

    /**
     * Moving, merging, archiving and replacing, whose refusal names its blockers.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function lifecycle(): array
    {
        return [
            CategoryOperationRefused::class => static fn (CategoryOperationRefused $refusal): ProblemRule => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.category_operation_refused',
                CategoryLifecycleController::TYPE_OPERATION_REFUSED,
                ['blockers' => array_map(static fn ($blocker): string => $blocker->value, $refusal->blockers)],
            ),
            ...self::invalid(),
            ...self::existing(),
            // Two lifecycle operations on overlapping branches lock their rows in the
            // order their own request implies, so PostgreSQL can break the tie. That is a
            // concurrency outcome the client should retry, not a server fault.
            RetryableException::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.category_concurrent_operation'),
            ...self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function invalid(): array
    {
        $invalid = new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_category');

        return [InvalidCategoryInput::class => $invalid, MalformedPayload::class => $invalid];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function existing(): array
    {
        return [
            CategoryNotFound::class => new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.category_not_found'),
            CategoryConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.category_conflict'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    private static function forbidden(): array
    {
        return [WorkspaceAccessDenied::class => new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.category_forbidden')];
    }
}

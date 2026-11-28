<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalBulkConflict;
use App\Module\Budget\Application\Goals\GoalConflict;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Transactions\UI\Http\TransactionProblems;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the goal endpoints becomes, and the direct
 * refusals their controllers answer themselves. Every goal problem carries its code.
 */
final readonly class GoalProblems
{
    /** A period or value type the model never accepts is a malformed request; the other refusals are unprocessable. */
    private const array BAD_REQUEST_CODES = ['invalid_goal_value_type', 'invalid_goal_period', 'invalid_goal_query'];

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    /**
     * Creating, editing and deleting a goal.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function write(): array
    {
        return [
            ...TransactionProblems::idempotency(),
            GoalInputError::class => self::inputByCode(),
            MalformedPayload::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_goal_target'),
            ...self::existing(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function stop(): array
    {
        return [
            GoalInputError::class => self::inputByCode(),
            MalformedPayload::class => self::rule(Response::HTTP_BAD_REQUEST, 'invalid_goal_period'),
            ...self::existing(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function bulk(): array
    {
        return [
            GoalInputError::class => self::unprocessableInput(),
            GoalBulkConflict::class => static fn (GoalBulkConflict $e): ProblemRule => self::rule(
                Response::HTTP_CONFLICT,
                $e->errorCode,
                ['goalId' => $e->goalId, 'field' => $e->field],
            ),
            ...self::read(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function preview(): array
    {
        return [
            GoalInputError::class => self::unprocessableInput(),
            MalformedPayload::class => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_goal_target'),
            ...self::existing(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function read(): array
    {
        return [
            GoalNotFound::class => self::rule(Response::HTTP_NOT_FOUND, 'goal_not_found'),
            WorkspaceAccessDenied::class => self::rule(Response::HTTP_FORBIDDEN, 'goal_forbidden'),
        ];
    }

    /** @param array<string, string> $details */
    public function problem(int $status, string $code, array $details = []): Response
    {
        $rule = self::rule($status, $code, $details);

        return $this->envelope->problem($rule->status, $rule->translationKey, $rule->type, $rule->extensions);
    }

    public function notFound(): Response
    {
        return $this->problem(Response::HTTP_NOT_FOUND, 'goal_not_found');
    }

    public function forbidden(): Response
    {
        return $this->problem(Response::HTTP_FORBIDDEN, 'goal_forbidden');
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    private static function existing(): array
    {
        return [
            GoalNotFound::class => self::rule(Response::HTTP_NOT_FOUND, 'goal_not_found'),
            GoalConflict::class => static fn (GoalConflict $e): ProblemRule => self::rule(Response::HTTP_CONFLICT, $e->errorCode),
            WorkspaceAccessDenied::class => self::rule(Response::HTTP_FORBIDDEN, 'goal_forbidden'),
        ];
    }

    private static function inputByCode(): \Closure
    {
        return static fn (GoalInputError $e): ProblemRule => self::rule(
            in_array($e->errorCode, self::BAD_REQUEST_CODES, true) ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNPROCESSABLE_ENTITY,
            $e->errorCode,
            $e->details,
        );
    }

    private static function unprocessableInput(): \Closure
    {
        return static fn (GoalInputError $e): ProblemRule => self::rule(Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->details);
    }

    /** @param array<string, string> $details */
    private static function rule(int $status, string $code, array $details = []): ProblemRule
    {
        return new ProblemRule($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code, ...$details]);
    }
}

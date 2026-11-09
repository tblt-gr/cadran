<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\CreateGoal;
use App\Module\Budget\Application\Goals\CreateGoalInput;
use App\Module\Budget\Application\Goals\DeleteGoal;
use App\Module\Budget\Application\Goals\GoalConflict;
use App\Module\Budget\Application\Goals\GoalEvaluationGranularity;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Budget\Application\Goals\GoalScheduleInput;
use App\Module\Budget\Application\Goals\ListGoalEvaluations;
use App\Module\Budget\Application\Goals\ListGoals;
use App\Module\Budget\Application\Goals\ReadGoal;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Budget\Application\Goals\StopGoal;
use App\Module\Budget\Application\Goals\StopGoalInput;
use App\Module\Budget\Application\Goals\UpdateGoal;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalController
{
    public function __construct(
        private BudgetHttpEnvelope $envelope,
        private TransactionHttpEnvelope $idempotencyEnvelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/goals', name: 'api_v1_goals_list', methods: ['GET'])]
    public function list(Request $request, ListGoals $list): Response
    {
        try {
            $rawCurrent = $request->query->get('current');
            if (null !== $rawCurrent && !in_array($rawCurrent, ['true', 'false'], true)) {
                throw new GoalInputError('invalid_goal_query', 'The current filter is true or false.');
            }
            $page = $list(
                $request->query->has('periodicity') ? $request->query->getString('periodicity') : null,
                $request->query->has('period') ? $request->query->getString('period') : null,
                'false' !== $rawCurrent,
                self::pagination($request, 'page', 1),
                self::pagination($request, 'pageSize', ListGoals::DEFAULT_PAGE_SIZE),
            );
        } catch (GoalInputError $error) {
            return $this->problem('future_goal_month' === $error->errorCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json([
            'items' => array_map(GoalRepresentation::summary(...), $page->items),
            'page' => $page->page,
            'pageSize' => $page->pageSize,
            'total' => $page->total,
        ]);
    }

    #[Route('/api/v1/goals/evaluations', name: 'api_v1_goals_evaluations', methods: ['GET'])]
    public function evaluations(Request $request, ListGoalEvaluations $evaluate): Response
    {
        try {
            $from = $request->query->get('from');
            $to = $request->query->get('to');
            if (!is_string($from) || !is_string($to)) {
                throw new GoalInputError('invalid_goal_query', 'An evaluation range needs from and to.');
            }

            $granularity = GoalEvaluationGranularity::tryFrom($request->query->getString('granularity', 'BOTH'));
            if (null === $granularity) {
                throw new GoalInputError('invalid_goal_query', 'A granularity is MONTH, YEAR or BOTH.');
            }

            return $this->envelope->json(GoalRepresentation::evaluations($evaluate($from, $to, $granularity)));
        } catch (GoalInputError $error) {
            return $this->problem(Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }
    }

    private static function pagination(Request $request, string $name, int $default): int
    {
        $raw = $request->query->get($name);
        if (null === $raw) {
            return $default;
        }
        if (1 !== preg_match('/^[0-9]{1,4}$/D', $raw)) {
            throw new GoalInputError('invalid_goal_query', 'A pagination value is a positive integer.');
        }

        return (int) $raw;
    }

    #[Route('/api/v1/goals/{id}/status', name: 'api_v1_goals_status', methods: ['GET'])]
    public function status(string $id, Request $request, ReadGoalStatus $read): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        try {
            $rawYear = $request->query->get('year');
            if (null !== $rawYear && 1 !== preg_match('/^[0-9]{4}$/D', $rawYear)) {
                throw new GoalInputError('invalid_goal_query', 'A year is four digits.');
            }

            return $this->envelope->json(GoalRepresentation::status($read($id, $request->query->get('month'), null === $rawYear ? null : (int) $rawYear)));
        } catch (GoalInputError $error) {
            return $this->problem('future_goal_month' === $error->errorCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }
    }

    #[Route('/api/v1/goals', name: 'api_v1_goals_create', methods: ['POST'])]
    public function create(Request $request, CreateGoal $create): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            self::allowed($body, ['label', 'periodicity', 'metric', 'scope', 'startPeriod', 'endPeriod', 'valueType', 'targetAmount', 'ratio', 'schedule', 'tolerance', 'direction'], ['label', 'periodicity', 'metric', 'scope', 'startPeriod', 'valueType']);
            $scope = self::object($body['scope']);
            self::allowed($scope, ['type', 'ref'], ['type', 'ref']);
            $target = null === ($body['targetAmount'] ?? null) ? null : self::object($body['targetAmount']);
            if (null !== $target) {
                self::allowed($target, ['value', 'assetCode'], ['value', 'assetCode']);
            }
            $input = (new CreateGoalInput(self::string($body, 'label'), self::string($body, 'periodicity'), self::string($body, 'metric'), self::string($scope, 'type'), self::nullableString($scope, 'ref'), self::string($body, 'startPeriod'), self::nullableString($body, 'endPeriod'), self::string($body, 'valueType'), null === $target ? null : self::string($target, 'value'), null === $target ? null : self::string($target, 'assetCode'), self::nullableString($body, 'ratio'), self::schedule($body), self::nullableString($body, 'tolerance'), self::nullableString($body, 'direction')));
            $result = $this->idempotentExecution->execute(
                'goal.create',
                $this->idempotencyEnvelope->idempotency($request, false),
                static function () use ($create, $input): IdempotentResponse {
                    $detail = $create($input);

                    return new IdempotentResponse(GoalRepresentation::detail($detail), Response::HTTP_CREATED, $detail->id);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->idempotencyEnvelope->idempotencyProblem($exception);
        } catch (GoalInputError $error) {
            return $this->problem(self::inputStatus($error), $error->errorCode, $error->details);
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_goal_target');
        } catch (GoalConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return new JsonResponse($result->body, $result->status, ['Cache-Control' => 'no-store', ...($result->replayed ? ['Idempotency-Replayed' => 'true'] : [])]);
    }

    #[Route('/api/v1/goals/{id}', name: 'api_v1_goals_read', methods: ['GET'])]
    public function read(string $id, ReadGoal $read): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        try {
            return $this->envelope->json(GoalRepresentation::detail($read($id)));
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }
    }

    #[Route('/api/v1/goals/{id}', name: 'api_v1_goals_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateGoal $update): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $update(GoalUpdateRequest::from($id, $body));
        } catch (GoalInputError $error) {
            return $this->problem(self::inputStatus($error), $error->errorCode, $error->details);
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_goal_target');
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (GoalConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(GoalRepresentation::detail($result));
    }

    #[Route('/api/v1/goals/{id}/stop', name: 'api_v1_goals_stop', methods: ['POST'])]
    public function stop(string $id, Request $request, StopGoal $stop): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            self::allowed($body, ['expectedVersion', 'lastPeriod'], ['expectedVersion', 'lastPeriod']);
            $version = $body['expectedVersion'];
            if (!is_int($version)) {
                throw new GoalInputError('invalid_goal_query', 'A goal version is an integer.');
            }
            $result = $stop(new StopGoalInput($id, $version, self::string($body, 'lastPeriod')));
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_goal_period');
        } catch (GoalInputError $error) {
            return $this->problem(self::inputStatus($error), $error->errorCode, $error->details);
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (GoalConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(GoalRepresentation::detail($result));
    }

    #[Route('/api/v1/goals/{id}', name: 'api_v1_goals_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request, DeleteGoal $delete): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $header = $request->headers->get('If-Match');
        if (null === $header) {
            return $this->problem(Response::HTTP_PRECONDITION_REQUIRED, 'goal_precondition_required');
        }
        if (1 !== preg_match('/^"?([1-9][0-9]{0,8})"?$/D', trim($header), $matches)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_goal_query');
        }
        try {
            $delete($id, (int) $matches[1]);
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (GoalConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }

    /** A period or value type the model never accepts is a malformed request; the other refusals are unprocessable. */
    private static function inputStatus(GoalInputError $error): int
    {
        return in_array($error->errorCode, ['invalid_goal_value_type', 'invalid_goal_period', 'invalid_goal_query'], true) ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    /** @param array<string, string> $details */
    private function problem(int $status, string $code, array $details = []): Response
    {
        return $this->envelope->problem($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code, ...$details]);
    }

    /**
     * Reads the optional `schedule` object: a mode, plus the twelve part
     * strings of a custom schedule.
     *
     * @param array<mixed> $body
     */
    private static function schedule(array $body): ?GoalScheduleInput
    {
        if (null === ($body['schedule'] ?? null)) {
            return null;
        }
        try {
            $schedule = self::object($body['schedule']);
            self::allowed($schedule, ['mode', 'parts'], ['mode']);
            $parts = $schedule['parts'] ?? null;
            if (null !== $parts && (!is_array($parts) || !array_is_list($parts) || count($parts) > 24)) {
                throw new \UnexpectedValueException('Schedule parts are a short list of decimal strings.');
            }

            return new GoalScheduleInput(self::string($schedule, 'mode'), null === $parts ? null : array_map(static fn (mixed $part): string => is_string($part) ? $part : throw new \UnexpectedValueException('A schedule part is a decimal string.'), $parts));
        } catch (\UnexpectedValueException $error) {
            throw new GoalInputError('invalid_goal_schedule', $error->getMessage());
        }
    }

    private function notFound(): Response
    {
        return $this->problem(Response::HTTP_NOT_FOUND, 'goal_not_found');
    }

    private function forbidden(): Response
    {
        return $this->problem(Response::HTTP_FORBIDDEN, 'goal_forbidden');
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $allowed
     * @param list<string> $required
     */
    private static function allowed(array $body, array $allowed, array $required): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \UnexpectedValueException('Unknown goal field.');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $body)) {
                throw new \UnexpectedValueException('Missing goal field.');
            }
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('A goal object is required.');
        }
        $fields = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A goal object is required.');
            }
            $fields[$key] = $item;
        }

        return $fields;
    }

    /** @param array<mixed> $body */
    private static function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('A goal string is required.');
        }

        return $value;
    }

    /** @param array<mixed> $body */
    private static function nullableString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException('A nullable goal string is required.');
        }

        return $value;
    }
}

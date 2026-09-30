<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\CloseGoal;
use App\Module\Budget\Application\Goals\CreateGoal;
use App\Module\Budget\Application\Goals\CreateGoalInput;
use App\Module\Budget\Application\Goals\GoalConflict;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Budget\Application\Goals\GoalScheduleInput;
use App\Module\Budget\Application\Goals\ListGoals;
use App\Module\Budget\Application\Goals\ReadGoal;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Budget\Application\Goals\UpdateGoal;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalController
{
    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/goals', name: 'api_v1_goals_list', methods: ['GET'])]
    public function list(Request $request, ListGoals $list): Response
    {
        try {
            $rawYear = $request->query->get('year');
            if (null !== $rawYear && 1 !== preg_match('/^[0-9]{4}$/D', $rawYear)) {
                throw new GoalInputError('invalid_goal_query', 'A year is four digits.');
            }
            $rawMonth = $request->query->get('month');
            $items = $list(null === $rawYear ? null : (int) $rawYear, $request->query->getString('state', 'active'), $rawMonth);
        } catch (GoalInputError $error) {
            return $this->problem('future_goal_month' === $error->errorCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(['items' => array_map(GoalRepresentation::summary(...), $items)]);
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
            self::allowed($body, ['label', 'kind', 'metric', 'scope', 'year', 'targetAmount', 'ratio', 'startMonth', 'schedule'], ['label', 'kind', 'metric', 'scope']);
            $scope = self::object($body['scope']);
            self::allowed($scope, ['type', 'ref'], ['type', 'ref']);
            $target = null === ($body['targetAmount'] ?? null) ? null : self::object($body['targetAmount']);
            if (null !== $target) {
                self::allowed($target, ['value', 'assetCode'], ['value', 'assetCode']);
            }
            $result = $create(new CreateGoalInput(self::string($body, 'label'), self::string($body, 'kind'), self::string($body, 'metric'), self::string($scope, 'type'), self::nullableString($scope, 'ref'), self::nullableInteger($body, 'year'), null === $target ? null : self::string($target, 'value'), null === $target ? null : self::string($target, 'assetCode'), self::nullableString($body, 'ratio'), self::nullableString($body, 'startMonth'), self::schedule($body)));
        } catch (GoalInputError $error) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $error->errorCode, $error->details);
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_goal_target');
        } catch (GoalConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(GoalRepresentation::detail($result), Response::HTTP_CREATED);
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
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $error->errorCode, $error->details);
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

    #[Route('/api/v1/goals/{id}/close', name: 'api_v1_goals_close', methods: ['POST'])]
    public function close(string $id, Request $request, CloseGoal $close): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            self::allowed($body, ['version'], ['version']);
            $result = $close($id, self::integer($body, 'version'));
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

    /** @param array<mixed> $body */
    private static function integer(array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException('A goal integer is required.');
        }

        return $value;
    }

    /** @param array<mixed> $body */
    private static function nullableInteger(array $body, string $field): ?int
    {
        $value = $body[$field] ?? null;
        if (null !== $value && !is_int($value)) {
            throw new \UnexpectedValueException('A nullable goal integer is required.');
        }

        return $value;
    }
}

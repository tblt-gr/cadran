<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalEvaluationGranularity;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Budget\Application\Goals\ListGoalEvaluations;
use App\Module\Budget\Application\Goals\ListGoals;
use App\Module\Budget\Application\Goals\ReadGoal;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalController
{
    public function __construct(private JsonRequestEnvelope $envelope, private GoalProblems $problems)
    {
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
            return $this->problems->problem('future_goal_month' === $error->errorCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->problems->forbidden();
        }

        return $this->envelope->json([
            'items' => array_map(GoalRepresentation::summary(...), $page->items),
            'page' => $page->page,
            'pageSize' => $page->pageSize,
            'total' => $page->total,
        ]);
    }

    #[Route('/api/v1/goals/evaluations', name: 'api_v1_goals_evaluations', methods: ['GET'])]
    public function evaluations(Request $request, ListGoalEvaluations $evaluate, \App\Module\Identity\Application\ReadInterfacePreferences $preferences): Response
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

            return $this->envelope->json(GoalRepresentation::evaluations($evaluate($from, $to, $granularity, $preferences()->includeUpcoming)));
        } catch (GoalInputError $error) {
            return $this->problems->problem(Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->problems->forbidden();
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
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        try {
            $rawYear = $request->query->get('year');
            if (null !== $rawYear && 1 !== preg_match('/^[0-9]{4}$/D', $rawYear)) {
                throw new GoalInputError('invalid_goal_query', 'A year is four digits.');
            }

            return $this->envelope->json(GoalRepresentation::status($read($id, $request->query->get('month'), null === $rawYear ? null : (int) $rawYear)));
        } catch (GoalInputError $error) {
            return $this->problems->problem('future_goal_month' === $error->errorCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (GoalNotFound) {
            return $this->problems->notFound();
        } catch (WorkspaceAccessDenied) {
            return $this->problems->forbidden();
        }
    }

    #[Route('/api/v1/goals/{id}', name: 'api_v1_goals_read', methods: ['GET'])]
    public function read(string $id, ReadGoal $read): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        try {
            return $this->envelope->json(GoalRepresentation::detail($read($id)));
        } catch (GoalNotFound) {
            return $this->problems->notFound();
        } catch (WorkspaceAccessDenied) {
            return $this->problems->forbidden();
        }
    }
}

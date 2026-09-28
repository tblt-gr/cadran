<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\PreviewGoalSchedule;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalSchedulePreviewController
{
    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    // Ranked above `/goals/{id}`, which would otherwise answer this path as an unknown goal.
    #[Route('/api/v1/goals/schedule-preview', name: 'api_v1_goals_schedule_preview', methods: ['GET'], priority: 10)]
    public function __invoke(Request $request, PreviewGoalSchedule $preview): Response
    {
        try {
            $target = $request->query->get('target');
            $mode = $request->query->get('mode');
            if (null === $target || null === $mode) {
                throw new GoalInputError('invalid_schedule_preview', 'A preview takes a target and a mode.');
            }

            return $this->envelope->json(GoalRepresentation::preview($preview($target, $mode)));
        } catch (GoalInputError $error) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.goal_error', '/problems/'.$error->errorCode, ['code' => $error->errorCode, ...$error->details]);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.goal_error', '/problems/goal_forbidden', ['code' => 'goal_forbidden']);
        }
    }
}

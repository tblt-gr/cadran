<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalConflict;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Budget\Application\Goals\ListGoalVersions;
use App\Module\Budget\Application\Goals\PreviewGoalCorrection;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalHistoryController
{
    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/goals/{id}/correction-preview', name: 'api_v1_goals_correction_preview', methods: ['POST'])]
    public function correctionPreview(string $id, Request $request, PreviewGoalCorrection $preview): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $preview(GoalUpdateRequest::from($id, $body));
        } catch (GoalInputError $error) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $error->errorCode, $error->details);
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_goal_target');
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (GoalConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'goal_forbidden');
        }

        return $this->envelope->json(GoalRepresentation::correctionPreview($result));
    }

    #[Route('/api/v1/goals/{id}/versions', name: 'api_v1_goals_versions', methods: ['GET'])]
    public function versions(string $id, ListGoalVersions $list): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        try {
            $result = $list($id);
        } catch (GoalNotFound) {
            return $this->notFound();
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'goal_forbidden');
        }

        return $this->envelope->json(GoalRepresentation::history($result));
    }

    /** @param array<string, string> $details */
    private function problem(int $status, string $code, array $details = []): Response
    {
        return $this->envelope->problem($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code, ...$details]);
    }

    private function notFound(): Response
    {
        return $this->problem(Response::HTTP_NOT_FOUND, 'goal_not_found');
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\ListGoalVersions;
use App\Module\Budget\Application\Goals\PreviewGoalCorrection;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalHistoryController
{
    public function __construct(private JsonRequestEnvelope $envelope, private GoalProblems $problems, private ProblemMapper $mapper)
    {
    }

    #[Route('/api/v1/goals/{id}/correction-preview', name: 'api_v1_goals_correction_preview', methods: ['POST'])]
    public function correctionPreview(string $id, Request $request, PreviewGoalCorrection $preview): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.budget_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $preview(GoalUpdateRequest::from($id, $body));
        } catch (\Throwable $exception) {
            return $this->mapper->respond($exception, GoalProblems::preview());
        }

        return $this->envelope->json(GoalRepresentation::correctionPreview($result));
    }

    #[Route('/api/v1/goals/{id}/versions', name: 'api_v1_goals_versions', methods: ['GET'])]
    public function versions(string $id, ListGoalVersions $list): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        try {
            $result = $list($id);
        } catch (\Throwable $exception) {
            return $this->mapper->respond($exception, GoalProblems::read());
        }

        return $this->envelope->json(GoalRepresentation::history($result));
    }
}

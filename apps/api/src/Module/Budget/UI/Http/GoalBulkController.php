<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\BulkGoalLifecycle;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalBulkController
{
    public function __construct(private JsonRequestEnvelope $envelope, private BulkGoalLifecycle $bulk, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/goals/bulk-stop', name: 'api_v1_goals_bulk_stop', methods: ['POST'])]
    public function stop(Request $request): Response
    {
        return $this->execute($request, true);
    }

    #[Route('/api/v1/goals/bulk-delete', name: 'api_v1_goals_bulk_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->execute($request, false);
    }

    private function execute(Request $request, bool $stop): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.budget_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        try {
            ($this->bulk)(GoalBulkRequest::from($body, $stop), $stop);

            return new Response(status: Response::HTTP_NO_CONTENT);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, GoalProblems::bulk());
        }
    }
}

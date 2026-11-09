<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\BulkGoalLifecycle;
use App\Module\Budget\Application\Goals\GoalBulkConflict;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalBulkController
{
    public function __construct(private BudgetHttpEnvelope $envelope, private BulkGoalLifecycle $bulk)
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
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            ($this->bulk)(GoalBulkRequest::from($body, $stop), $stop);

            return new Response(status: Response::HTTP_NO_CONTENT);
        } catch (GoalInputError $error) {
            return $this->problem(422, $error->errorCode, $error->details);
        } catch (GoalBulkConflict $error) {
            return $this->problem(409, $error->errorCode, ['goalId' => $error->goalId, 'field' => $error->field]);
        } catch (GoalNotFound) {
            return $this->problem(404, 'goal_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, 'goal_forbidden');
        }
    }

    /** @param array<string, string> $details */
    private function problem(int $status, string $code, array $details = []): Response
    {
        return $this->envelope->problem($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code, ...$details]);
    }
}

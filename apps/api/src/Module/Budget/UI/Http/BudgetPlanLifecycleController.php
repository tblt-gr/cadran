<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\ActivateBudgetPlan;
use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\BudgetPlanNotFound;
use App\Module\Budget\Application\CloseBudgetPlan;
use App\Module\Budget\Application\EmptyBudgetPlan;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BudgetPlanLifecycleController
{
    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/budget-plans/{id}/activate', name: 'api_v1_budget_plans_activate', methods: ['POST'])]
    public function activate(string $id, Request $request, ActivateBudgetPlan $activate): Response
    {
        return $this->transition($id, $request, $activate);
    }

    #[Route('/api/v1/budget-plans/{id}/close', name: 'api_v1_budget_plans_close', methods: ['POST'])]
    public function close(string $id, Request $request, CloseBudgetPlan $close): Response
    {
        return $this->transition($id, $request, $close);
    }

    /** @param callable(string): \App\Module\Budget\Application\BudgetPlanView $operation */
    private function transition(string $id, Request $request, callable $operation): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            BudgetPayload::of($body, []);
            $result = $operation($id);
        } catch (EmptyBudgetPlan|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_budget_plan');
        } catch (BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetPlanConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.budget_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.budget_forbidden');
        }

        return $this->envelope->json(BudgetRepresentation::plan($result));
    }

    private function notFound(): Response
    {
        return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.budget_plan_not_found');
    }
}

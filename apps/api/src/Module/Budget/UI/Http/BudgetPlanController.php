<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\BudgetComparisonScopeTooLarge;
use App\Module\Budget\Application\BudgetComparisonUnavailable;
use App\Module\Budget\Application\BudgetIncomeScopeTooLarge;
use App\Module\Budget\Application\BudgetKpiExplanationNotFound;
use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\BudgetPlanNotFound;
use App\Module\Budget\Application\CreateBudgetPlan;
use App\Module\Budget\Application\CreateBudgetPlanInput;
use App\Module\Budget\Application\InvalidBudgetPlanInput;
use App\Module\Budget\Application\ListBudgetPlans;
use App\Module\Budget\Application\ReadBudgetComparisons;
use App\Module\Budget\Application\ReadBudgetKpiExplanation;
use App\Module\Budget\Application\ReadBudgetPlan;
use App\Module\Budget\Application\UpdateBudgetPlan;
use App\Module\Budget\Application\UpdateBudgetPlanInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Reporting\UI\Http\MonthlyKpiExplanationRepresentation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BudgetPlanController
{
    private const array CREATE_FIELDS = ['periodType', 'period', 'assetCode'];
    private const array UPDATE_FIELDS = ['periodType', 'period', 'assetCode', 'version'];

    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/budget-plans', name: 'api_v1_budget_plans_list', methods: ['GET'])]
    public function list(Request $request, ListBudgetPlans $list): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        if (!self::boundedInteger($page, 1, 1000) || !self::boundedInteger($perPage, 1, 100)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_budget_query');
        }

        try {
            $result = $list('' === $page ? 1 : (int) $page, '' === $perPage ? 50 : (int) $perPage);
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::page($result));
    }

    #[Route('/api/v1/budget-plans', name: 'api_v1_budget_plans_create', methods: ['POST'])]
    public function create(Request $request, CreateBudgetPlan $create): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = BudgetPayload::of($body, self::CREATE_FIELDS);
            $result = $create(new CreateBudgetPlanInput($payload->string('periodType'), $payload->string('period'), $payload->string('assetCode')));
        } catch (InvalidBudgetPlanInput|\UnexpectedValueException) {
            return $this->invalid();
        } catch (BudgetPlanConflict) {
            return $this->conflict();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::plan($result), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/budget-plans/{id}', name: 'api_v1_budget_plans_read', methods: ['GET'])]
    public function read(string $id, ReadBudgetPlan $read): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        try {
            $result = $read($id);
        } catch (BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetIncomeScopeTooLarge|\LengthException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.budget_scope_too_large');
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::detail($result));
    }

    #[Route('/api/v1/budget-plans/{id}/comparisons', name: 'api_v1_budget_plan_comparisons', methods: ['GET'])]
    public function comparisons(string $id, ReadBudgetComparisons $read): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        try {
            $result = $read($id);
        } catch (BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetComparisonUnavailable) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.budget_comparison_unavailable');
        } catch (BudgetComparisonScopeTooLarge) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.budget_scope_too_large');
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::comparisons($result));
    }

    #[Route('/api/v1/budget-plans/{id}/comparisons/{targetId}/explain', name: 'api_v1_budget_comparison_explain', methods: ['GET'])]
    public function explainComparison(string $id, string $targetId, ReadBudgetKpiExplanation $read): Response
    {
        if (!$this->envelope->isIdentifier($id) || !$this->envelope->isIdentifier($targetId)) {
            return $this->notFound();
        }
        try {
            $result = $read($id, $targetId);
        } catch (BudgetPlanNotFound|BudgetKpiExplanationNotFound) {
            return $this->notFound();
        } catch (BudgetComparisonUnavailable) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.budget_comparison_unavailable');
        } catch (BudgetComparisonScopeTooLarge) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.budget_scope_too_large');
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(MonthlyKpiExplanationRepresentation::of($result));
    }

    #[Route('/api/v1/budget-plans/{id}', name: 'api_v1_budget_plans_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateBudgetPlan $update): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = BudgetPayload::of($body, self::UPDATE_FIELDS);
            $result = $update(new UpdateBudgetPlanInput(
                $id, $payload->integer('version'), $payload->string('periodType'), $payload->string('period'), $payload->string('assetCode'),
            ));
        } catch (InvalidBudgetPlanInput|\UnexpectedValueException) {
            return $this->invalid();
        } catch (BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetPlanConflict) {
            return $this->conflict();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::plan($result));
    }

    private static function boundedInteger(string $value, int $minimum, int $maximum): bool
    {
        return '' === $value || (1 === preg_match('/^[0-9]{1,4}$/D', $value) && (int) $value >= $minimum && (int) $value <= $maximum);
    }

    private function invalid(): Response
    {
        return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_budget_plan');
    }

    private function notFound(): Response
    {
        return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.budget_plan_not_found');
    }

    private function conflict(): Response
    {
        return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.budget_conflict');
    }

    private function forbidden(): Response
    {
        return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.budget_forbidden');
    }
}

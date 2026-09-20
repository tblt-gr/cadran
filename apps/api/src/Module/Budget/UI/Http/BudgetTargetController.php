<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\BudgetPlanConflict;
use App\Module\Budget\Application\BudgetPlanNotFound;
use App\Module\Budget\Application\BudgetTargetNotFound;
use App\Module\Budget\Application\CreateBudgetTarget;
use App\Module\Budget\Application\CreateBudgetTargetInput;
use App\Module\Budget\Application\DeleteBudgetTarget;
use App\Module\Budget\Application\InvalidBudgetTargetInput;
use App\Module\Budget\Application\InvalidBudgetTargetReference;
use App\Module\Budget\Application\UpdateBudgetTarget;
use App\Module\Budget\Application\UpdateBudgetTargetInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BudgetTargetController
{
    private const array CREATE_FIELDS = ['scopeType', 'scopeId', 'valueType', 'amount', 'ratio'];
    private const array DELETE_FIELDS = ['version'];
    private const array UPDATE_FIELDS = ['valueType', 'amount', 'ratio', 'version'];

    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/budget-plans/{planId}/targets', name: 'api_v1_budget_targets_create', methods: ['POST'])]
    public function create(string $planId, Request $request, CreateBudgetTarget $create): Response
    {
        if (!$this->envelope->isIdentifier($planId)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = BudgetPayload::of($body, self::CREATE_FIELDS);
            $result = $create(new CreateBudgetTargetInput(
                $planId, $payload->string('scopeType'), $payload->string('scopeId'), $payload->string('valueType'),
                $payload->nullableString('amount'), $payload->nullableString('ratio'),
            ));
        } catch (InvalidBudgetTargetInput|InvalidBudgetTargetReference|\UnexpectedValueException) {
            return $this->invalid();
        } catch (BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetPlanConflict) {
            return $this->conflict();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::target($result), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/budget-targets/{id}', name: 'api_v1_budget_targets_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateBudgetTarget $update): Response
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
            $result = $update(new UpdateBudgetTargetInput(
                $id, $payload->integer('version'), $payload->string('valueType'),
                $payload->nullableString('amount'), $payload->nullableString('ratio'),
            ));
        } catch (InvalidBudgetTargetInput|\UnexpectedValueException) {
            return $this->invalid();
        } catch (BudgetTargetNotFound|BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetPlanConflict) {
            return $this->conflict();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return $this->envelope->json(BudgetRepresentation::target($result));
    }

    #[Route('/api/v1/budget-targets/{id}', name: 'api_v1_budget_targets_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request, DeleteBudgetTarget $delete): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->notFound();
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = BudgetPayload::of($body, self::DELETE_FIELDS);
            $delete($id, $payload->integer('version'));
        } catch (InvalidBudgetTargetInput|\UnexpectedValueException) {
            return $this->invalid();
        } catch (BudgetTargetNotFound|BudgetPlanNotFound) {
            return $this->notFound();
        } catch (BudgetPlanConflict) {
            return $this->conflict();
        } catch (WorkspaceAccessDenied) {
            return $this->forbidden();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function invalid(): Response
    {
        return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_budget_target');
    }

    private function notFound(): Response
    {
        return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.budget_target_not_found');
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

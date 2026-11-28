<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\CreateGoal;
use App\Module\Budget\Application\Goals\DeleteGoal;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\StopGoal;
use App\Module\Budget\Application\Goals\StopGoalInput;
use App\Module\Budget\Application\Goals\UpdateGoal;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalWriteController
{
    public function __construct(
        private JsonRequestEnvelope $envelope,
        private TransactionHttpEnvelope $idempotencyEnvelope,
        private IdempotentExecution $idempotentExecution,
        private GoalProblems $problems,
        private ProblemMapper $mapper,
    ) {
    }

    #[Route('/api/v1/goals', name: 'api_v1_goals_create', methods: ['POST'])]
    public function create(Request $request, CreateGoal $create): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.budget_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = GoalCreateRequest::from($body);
            $result = $this->idempotentExecution->execute(
                'goal.create',
                $this->idempotencyEnvelope->idempotency($request, false),
                static function () use ($create, $input): IdempotentResponse {
                    $detail = $create($input);

                    return new IdempotentResponse(GoalRepresentation::detail($detail), Response::HTTP_CREATED, $detail->id);
                },
            );
        } catch (\Throwable $exception) {
            return $this->mapper->respond($exception, GoalProblems::write());
        }

        return $this->idempotencyEnvelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/goals/{id}', name: 'api_v1_goals_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateGoal $update): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.budget_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $update(GoalUpdateRequest::from($id, $body));
        } catch (\Throwable $exception) {
            return $this->mapper->respond($exception, GoalProblems::write());
        }

        return $this->envelope->json(GoalRepresentation::detail($result));
    }

    #[Route('/api/v1/goals/{id}/stop', name: 'api_v1_goals_stop', methods: ['POST'])]
    public function stop(string $id, Request $request, StopGoal $stop): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.budget_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = JsonPayload::of($body, ['expectedVersion', 'lastPeriod']);
            $version = $payload->raw('expectedVersion');
            if (!is_int($version)) {
                throw new GoalInputError('invalid_goal_query', 'A goal version is an integer.');
            }
            $result = $stop(new StopGoalInput($id, $version, $payload->string('lastPeriod')));
        } catch (\Throwable $exception) {
            return $this->mapper->respond($exception, GoalProblems::stop());
        }

        return $this->envelope->json(GoalRepresentation::detail($result));
    }

    #[Route('/api/v1/goals/{id}', name: 'api_v1_goals_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request, DeleteGoal $delete): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->notFound();
        }
        $header = $request->headers->get('If-Match');
        if (null === $header) {
            return $this->problems->problem(Response::HTTP_PRECONDITION_REQUIRED, 'goal_precondition_required');
        }
        if (1 !== preg_match('/^"?([1-9][0-9]{0,8})"?$/D', trim($header), $matches)) {
            return $this->problems->problem(Response::HTTP_BAD_REQUEST, 'invalid_goal_query');
        }
        try {
            $delete($id, (int) $matches[1]);
        } catch (\Throwable $exception) {
            return $this->mapper->respond($exception, GoalProblems::write());
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }
}

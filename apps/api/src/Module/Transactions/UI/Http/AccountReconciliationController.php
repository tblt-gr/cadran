<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\Reconciliation\ReadAccountReconciliation;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalance;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalanceInput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Compares an observed account balance to its period's movements and resolves the gap. */
final readonly class AccountReconciliationController
{
    private const array RECONCILE_FIELDS = ['snapshotId', 'snapshotVersion', 'periodStart', 'resolution'];
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private ProblemMapper $problems,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/reconciliation', name: 'api_v1_account_reconciliation_read', methods: ['GET'])]
    public function read(string $accountId, Request $request, ReadAccountReconciliation $readAccountReconciliation): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->notFound();
        }
        $snapshotId = $request->query->get('snapshotId');
        $periodStart = $request->query->get('periodStart');
        if (!is_string($snapshotId) || !Identifier::isValid($snapshotId)
            || !is_string($periodStart) || 1 !== preg_match(self::DATE_PATTERN, $periodStart)
            || [] !== array_diff(array_keys($request->query->all()), ['snapshotId', 'periodStart'])) {
            return $this->invalid();
        }

        try {
            $view = $readAccountReconciliation($accountId, $snapshotId, $periodStart);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::accountReconciliationRead());
        }

        return $this->envelope->json(AccountReconciliationRepresentation::one($view));
    }

    #[Route('/api/v1/accounts/{accountId}/reconciliation', name: 'api_v1_account_reconciliation_resolve', methods: ['POST'])]
    public function resolve(string $accountId, Request $request, ReconcileAccountBalance $reconcileAccountBalance): Response
    {
        if (!Identifier::isValid($accountId)) {
            return $this->notFound();
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, self::RECONCILE_FIELDS);
            $input = new ReconcileAccountBalanceInput(
                $payload->uuid('snapshotId'),
                $payload->integer('snapshotVersion'),
                $payload->string('periodStart'),
                $payload->string('resolution'),
            );
            $result = $this->idempotentExecution->execute(
                'account.reconcile',
                $this->envelope->idempotency($request, false),
                static function () use ($reconcileAccountBalance, $accountId, $input): IdempotentResponse {
                    $view = $reconcileAccountBalance($accountId, $input);

                    return new IdempotentResponse(AccountReconciliationRepresentation::one($view), Response::HTTP_OK, $view->snapshotId, [$view->periodEnd]);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::accountReconciliationResolve());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    private function notFound(): Response
    {
        return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_reconciliation_not_found');
    }

    private function invalid(): Response
    {
        return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_reconciliation_invalid');
    }
}

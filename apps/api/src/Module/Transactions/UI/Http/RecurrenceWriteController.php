<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\Recurrence\ArchiveRecurrence;
use App\Module\Transactions\Application\Recurrence\ConfirmRecurrence;
use App\Module\Transactions\Application\Recurrence\DismissRecurrenceCandidate;
use App\Module\Transactions\Application\Recurrence\EditRecurrence;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceInput;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceReference;
use App\Module\Transactions\Application\Recurrence\MaterializeRecurrenceOccurrences;
use App\Module\Transactions\Application\Recurrence\RecurrenceEditInput;
use App\Module\Transactions\Application\Recurrence\RecurrenceInput;
use App\Module\Transactions\Application\Recurrence\RecurrenceNotFound;
use App\Module\Transactions\Application\Recurrence\RefreshOccurrenceHorizon;
use App\Module\Transactions\Application\Recurrence\RestoreRecurrence;
use App\Module\Transactions\Application\Recurrence\RestoreRecurrenceCandidate;
use App\Module\Transactions\Application\Recurrence\StaleRecurrence;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RecurrenceWriteController
{
    private const array CREATE_FIELDS = [
        'accountId', 'label', 'counterparty', 'expectedAmount', 'amountTolerance',
        'intervalKind', 'dayOfPeriod', 'firstExpectedOn',
    ];
    private const array UPDATE_FIELDS = [
        'label', 'counterparty', 'expectedAmount', 'amountTolerance', 'intervalKind', 'dayOfPeriod', 'version',
    ];
    /** Left out of a body, they keep their neutral default: a recurrence that does not create transactions. */
    private const array AUTO_CREATE_FIELDS = ['autoCreate', 'categoryId', 'note', 'paymentMethod'];
    private const array UPDATE_OPTIONAL_FIELDS = [...self::AUTO_CREATE_FIELDS, 'firstExpectedOn'];

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
        private RecurrenceProblems $problems,
    ) {
    }

    #[Route('/api/v1/recurrence-candidates/dismiss', name: 'api_v1_recurrence_candidates_dismiss', methods: ['POST'])]
    public function dismiss(Request $request, DismissRecurrenceCandidate $dismiss): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $fingerprint = JsonPayload::of($body, ['fingerprint'])->string('fingerprint');
            self::fingerprint($fingerprint);
            $dismiss($fingerprint);

            return new Response(status: 204);
        } catch (\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrence-candidates/dismissals/{fingerprint}', name: 'api_v1_recurrence_candidates_restore', methods: ['DELETE'])]
    public function restore(string $fingerprint, RestoreRecurrenceCandidate $restore): Response
    {
        if (!self::validFingerprint($fingerprint)) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        }
        try {
            $restore($fingerprint);

            return new Response(status: 204);
        } catch (RecurrenceNotFound) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences', name: 'api_v1_recurrences_create', methods: ['POST'])]
    public function create(Request $request, ConfirmRecurrence $confirm): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = JsonPayload::of($body, self::CREATE_FIELDS, self::AUTO_CREATE_FIELDS);
            $input = new RecurrenceInput(
                $payload->uuid('accountId'), $payload->string('label'), $payload->nullableString('counterparty'),
                $payload->string('expectedAmount'), $payload->string('amountTolerance'), $payload->string('intervalKind'),
                $payload->integer('dayOfPeriod'), $payload->string('firstExpectedOn'),
                null !== $payload->raw('autoCreate') && $payload->boolean('autoCreate'), $payload->nullableUuid('categoryId'),
                $payload->nullableString('note'), $payload->nullableString('paymentMethod'),
            );
            $result = $this->idempotentExecution->execute(
                'recurrence.create',
                $this->envelope->idempotency($request, false),
                static function () use ($confirm, $input): IdempotentResponse {
                    $view = $confirm($input);

                    return new IdempotentResponse($view, 201, is_string($view['id'] ?? null) ? $view['id'] : null);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidRecurrenceInput|InvalidRecurrenceReference|\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/recurrences/refresh-horizon', name: 'api_v1_recurrences_refresh_horizon', methods: ['POST'], priority: 10)]
    public function refresh(Request $request, RefreshOccurrenceHorizon $refresh): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            JsonPayload::of($body, []);

            return $this->envelope->json($refresh());
        } catch (\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences/materialize', name: 'api_v1_recurrences_materialize', methods: ['POST'], priority: 10)]
    public function materialize(Request $request, MaterializeRecurrenceOccurrences $materialize): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            JsonPayload::of($body, []);

            return $this->envelope->json($materialize()->toArray());
        } catch (\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences/{id}', name: 'api_v1_recurrences_update', methods: ['PUT'])]
    public function update(string $id, Request $request, EditRecurrence $edit): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = JsonPayload::of($body, self::UPDATE_FIELDS, self::UPDATE_OPTIONAL_FIELDS);
            $view = $edit($id, new RecurrenceEditInput(
                $payload->string('label'), $payload->nullableString('counterparty'), $payload->string('expectedAmount'),
                $payload->string('amountTolerance'), $payload->string('intervalKind'), $payload->integer('dayOfPeriod'),
                $payload->integer('version'), null !== $payload->raw('autoCreate') && $payload->boolean('autoCreate'), $payload->nullableUuid('categoryId'),
                $payload->nullableString('note'), $payload->nullableString('paymentMethod'),
                $payload->nullableString('firstExpectedOn'),
            ));
        } catch (RecurrenceNotFound) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        } catch (StaleRecurrence $exception) {
            return $this->problems->problem(409, StaleRecurrence::ARCHIVED === $exception->getMessage() ? '/problems/recurrences.archived' : '/problems/stale-version');
        } catch (InvalidRecurrenceInput|InvalidRecurrenceReference|\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }

        return $this->envelope->json($view);
    }

    #[Route('/api/v1/recurrences/{id}/archive', name: 'api_v1_recurrences_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveRecurrence $archive): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $version = JsonPayload::of($body, ['version'])->integer('version');

            return $this->envelope->json($archive($id, $version));
        } catch (RecurrenceNotFound) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        } catch (StaleRecurrence $exception) {
            return $this->problems->problem(409, StaleRecurrence::ARCHIVED === $exception->getMessage() ? '/problems/recurrences.archived' : '/problems/stale-version');
        } catch (InvalidRecurrenceInput|\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences/{id}/restore', name: 'api_v1_recurrences_restore', methods: ['POST'])]
    public function restoreRecurrence(string $id, Request $request, RestoreRecurrence $restore): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $version = JsonPayload::of($body, ['version'])->integer('version');

            return $this->envelope->json($restore($id, $version));
        } catch (RecurrenceNotFound) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        } catch (StaleRecurrence $exception) {
            return $this->problems->problem(409, StaleRecurrence::NOT_ARCHIVED === $exception->getMessage() ? '/problems/recurrences.not_archived' : '/problems/stale-version');
        } catch (InvalidRecurrenceInput|\UnexpectedValueException) {
            return $this->problems->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    private static function fingerprint(string $value): void
    {
        if (!self::validFingerprint($value)) {
            throw new \UnexpectedValueException('A candidate fingerprint is a SHA-256 digest.');
        }
    }

    private static function validFingerprint(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{64}$/D', $value);
    }
}

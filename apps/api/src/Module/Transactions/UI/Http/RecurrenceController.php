<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\Recurrence\ArchiveRecurrence;
use App\Module\Transactions\Application\Recurrence\ConfirmRecurrence;
use App\Module\Transactions\Application\Recurrence\DetectRecurrenceCandidates;
use App\Module\Transactions\Application\Recurrence\DismissRecurrenceCandidate;
use App\Module\Transactions\Application\Recurrence\EditRecurrence;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceInput;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceReference;
use App\Module\Transactions\Application\Recurrence\ListRecurrenceOccurrences;
use App\Module\Transactions\Application\Recurrence\ListRecurrences;
use App\Module\Transactions\Application\Recurrence\RecurrenceEditInput;
use App\Module\Transactions\Application\Recurrence\RecurrenceInput;
use App\Module\Transactions\Application\Recurrence\RecurrenceNotFound;
use App\Module\Transactions\Application\Recurrence\RefreshOccurrenceHorizon;
use App\Module\Transactions\Application\Recurrence\RestoreRecurrenceCandidate;
use App\Module\Transactions\Application\Recurrence\StaleRecurrence;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RecurrenceController
{
    private const array CREATE_FIELDS = [
        'accountId', 'label', 'counterparty', 'expectedAmount', 'amountTolerance',
        'intervalKind', 'dayOfPeriod', 'firstExpectedOn',
    ];
    private const array UPDATE_FIELDS = [
        'label', 'counterparty', 'expectedAmount', 'amountTolerance', 'intervalKind', 'dayOfPeriod', 'version',
    ];

    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/recurrence-candidates', name: 'api_v1_recurrence_candidates_list', methods: ['GET'])]
    public function candidates(Request $request, DetectRecurrenceCandidates $detect): Response
    {
        if ([] !== $request->query->all()) {
            return $this->problem(400, '/problems/recurrences.invalid_query');
        }
        try {
            return $this->envelope->json($detect());
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrence-candidates/dismiss', name: 'api_v1_recurrence_candidates_dismiss', methods: ['POST'])]
    public function dismiss(Request $request, DismissRecurrenceCandidate $dismiss): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $fingerprint = RecurrencePayload::of($body, ['fingerprint'])->string('fingerprint');
            self::fingerprint($fingerprint);
            $dismiss($fingerprint);

            return new Response(status: 204);
        } catch (\UnexpectedValueException) {
            return $this->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrence-candidates/dismissals/{fingerprint}', name: 'api_v1_recurrence_candidates_restore', methods: ['DELETE'])]
    public function restore(string $fingerprint, RestoreRecurrenceCandidate $restore): Response
    {
        if (!self::validFingerprint($fingerprint)) {
            return $this->problem(404, '/problems/recurrences.not_found');
        }
        try {
            $restore($fingerprint);

            return new Response(status: 204);
        } catch (RecurrenceNotFound) {
            return $this->problem(404, '/problems/recurrences.not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences', name: 'api_v1_recurrences_list', methods: ['GET'])]
    public function list(Request $request, ListRecurrences $list): Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), ['includeArchived', 'page', 'perPage'])) {
            return $this->problem(400, '/problems/recurrences.invalid_query');
        }
        $archived = $request->query->getString('includeArchived');
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        if (!in_array($archived, ['', 'true', 'false'], true) || !self::digits($page) || !self::digits($perPage)) {
            return $this->problem(400, '/problems/recurrences.invalid_query');
        }
        try {
            return $this->envelope->json($list('true' === $archived, '' === $page ? 1 : (int) $page, '' === $perPage ? 50 : (int) $perPage));
        } catch (InvalidRecurrenceInput) {
            return $this->problem(400, '/problems/recurrences.invalid_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences', name: 'api_v1_recurrences_create', methods: ['POST'])]
    public function create(Request $request, ConfirmRecurrence $confirm): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = RecurrencePayload::of($body, self::CREATE_FIELDS);
            $view = $confirm(new RecurrenceInput(
                $payload->identifier('accountId'), $payload->string('label'), $payload->nullableString('counterparty'),
                $payload->string('expectedAmount'), $payload->string('amountTolerance'), $payload->string('intervalKind'),
                $payload->integer('dayOfPeriod'), $payload->string('firstExpectedOn'),
            ));
        } catch (InvalidRecurrenceInput|InvalidRecurrenceReference|\UnexpectedValueException) {
            return $this->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }

        return $this->envelope->json($view, 201);
    }

    #[Route('/api/v1/recurrences/refresh-horizon', name: 'api_v1_recurrences_refresh_horizon', methods: ['POST'], priority: 10)]
    public function refresh(Request $request, RefreshOccurrenceHorizon $refresh): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            RecurrencePayload::of($body, []);

            return $this->envelope->json($refresh());
        } catch (\UnexpectedValueException) {
            return $this->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences/{id}', name: 'api_v1_recurrences_update', methods: ['PUT'])]
    public function update(string $id, Request $request, EditRecurrence $edit): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->problem(404, '/problems/recurrences.not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = RecurrencePayload::of($body, self::UPDATE_FIELDS);
            $view = $edit($id, new RecurrenceEditInput(
                $payload->string('label'), $payload->nullableString('counterparty'), $payload->string('expectedAmount'),
                $payload->string('amountTolerance'), $payload->string('intervalKind'), $payload->integer('dayOfPeriod'),
                $payload->integer('version'),
            ));
        } catch (RecurrenceNotFound) {
            return $this->problem(404, '/problems/recurrences.not_found');
        } catch (StaleRecurrence $exception) {
            return $this->problem(409, StaleRecurrence::ARCHIVED === $exception->getMessage() ? '/problems/recurrences.archived' : '/problems/stale-version');
        } catch (InvalidRecurrenceInput|InvalidRecurrenceReference|\UnexpectedValueException) {
            return $this->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }

        return $this->envelope->json($view);
    }

    #[Route('/api/v1/recurrences/{id}/archive', name: 'api_v1_recurrences_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveRecurrence $archive): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->problem(404, '/problems/recurrences.not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $version = RecurrencePayload::of($body, ['version'])->integer('version');

            return $this->envelope->json($archive($id, $version));
        } catch (RecurrenceNotFound) {
            return $this->problem(404, '/problems/recurrences.not_found');
        } catch (StaleRecurrence $exception) {
            return $this->problem(409, StaleRecurrence::ARCHIVED === $exception->getMessage() ? '/problems/recurrences.archived' : '/problems/stale-version');
        } catch (InvalidRecurrenceInput|\UnexpectedValueException) {
            return $this->problem(422, '/problems/recurrences.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences/{id}/occurrences', name: 'api_v1_recurrences_occurrences', methods: ['GET'])]
    public function occurrences(string $id, Request $request, ListRecurrenceOccurrences $list): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->problem(404, '/problems/recurrences.not_found');
        }
        if ([] !== array_diff(array_keys($request->query->all()), ['from', 'to'])) {
            return $this->problem(400, '/problems/recurrences.invalid_query');
        }
        try {
            $from = $request->query->has('from') ? self::day($request->query->getString('from')) : null;
            $to = $request->query->has('to') ? self::day($request->query->getString('to')) : null;
            if ((null === $from) !== (null === $to)) {
                throw new InvalidRecurrenceInput();
            }

            return $this->envelope->json($list($id, $from, $to));
        } catch (RecurrenceNotFound) {
            return $this->problem(404, '/problems/recurrences.not_found');
        } catch (InvalidRecurrenceInput|\UnexpectedValueException) {
            return $this->problem(400, '/problems/recurrences.invalid_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/recurrences.forbidden');
        }
    }

    private function problem(int $status, string $type): Response
    {
        $translation = match ($type) {
            '/problems/recurrences.invalid_query' => 'api.problem.invalid_recurrence_query',
            '/problems/recurrences.not_found' => 'api.problem.recurrence_not_found',
            '/problems/recurrences.forbidden' => 'api.problem.recurrence_forbidden',
            '/problems/recurrences.archived' => 'api.problem.recurrence_archived',
            '/problems/stale-version' => 'api.problem.recurrence_stale_version',
            default => 'api.problem.invalid_recurrence',
        };

        return $this->envelope->problem($status, $translation, $type);
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

    private static function digits(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,3}$/D', $value);
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return \App\Module\Transactions\Application\Recurrence\RecurrenceFactory::day($value);
    }
}

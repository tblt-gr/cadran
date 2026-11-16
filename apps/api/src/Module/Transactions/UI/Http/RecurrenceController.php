<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Transactions\Application\Recurrence\DetectRecurrenceCandidates;
use App\Module\Transactions\Application\Recurrence\InvalidRecurrenceInput;
use App\Module\Transactions\Application\Recurrence\ListRecurrenceOccurrences;
use App\Module\Transactions\Application\Recurrence\ListRecurrences;
use App\Module\Transactions\Application\Recurrence\RecurrenceNotFound;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RecurrenceController
{
    public function __construct(private TransactionHttpEnvelope $envelope, private RecurrenceProblems $problems)
    {
    }

    #[Route('/api/v1/recurrence-candidates', name: 'api_v1_recurrence_candidates_list', methods: ['GET'])]
    public function candidates(Request $request, DetectRecurrenceCandidates $detect): Response
    {
        if ([] !== $request->query->all()) {
            return $this->problems->problem(400, '/problems/recurrences.invalid_query');
        }
        try {
            return $this->envelope->json($detect());
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences', name: 'api_v1_recurrences_list', methods: ['GET'])]
    public function list(Request $request, ListRecurrences $list): Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), ['includeArchived', 'page', 'pageSize'])) {
            return $this->problems->problem(400, '/problems/recurrences.invalid_query');
        }
        $archived = $request->query->getString('includeArchived');
        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        if (!in_array($archived, ['', 'true', 'false'], true) || !self::digits($page) || !self::digits($pageSize)) {
            return $this->problems->problem(400, '/problems/recurrences.invalid_query');
        }
        try {
            return $this->envelope->json($list('true' === $archived, '' === $page ? 1 : (int) $page, '' === $pageSize ? 50 : (int) $pageSize));
        } catch (InvalidRecurrenceInput) {
            return $this->problems->problem(400, '/problems/recurrences.invalid_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
    }

    #[Route('/api/v1/recurrences/{id}/occurrences', name: 'api_v1_recurrences_occurrences', methods: ['GET'])]
    public function occurrences(string $id, Request $request, ListRecurrenceOccurrences $list): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        }
        if ([] !== array_diff(array_keys($request->query->all()), ['from', 'to'])) {
            return $this->problems->problem(400, '/problems/recurrences.invalid_query');
        }
        try {
            $from = $request->query->has('from') ? self::day($request->query->getString('from')) : null;
            $to = $request->query->has('to') ? self::day($request->query->getString('to')) : null;
            if ((null === $from) !== (null === $to)) {
                throw new InvalidRecurrenceInput();
            }

            return $this->envelope->json($list($id, $from, $to));
        } catch (RecurrenceNotFound) {
            return $this->problems->problem(404, '/problems/recurrences.not_found');
        } catch (InvalidRecurrenceInput|\UnexpectedValueException) {
            return $this->problems->problem(400, '/problems/recurrences.invalid_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problems->problem(403, '/problems/recurrences.forbidden');
        }
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

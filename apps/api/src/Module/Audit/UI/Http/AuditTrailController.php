<?php

declare(strict_types=1);

namespace App\Module\Audit\UI\Http;

use App\Module\Audit\Application\AuditEventNotFound;
use App\Module\Audit\Application\AuditTrailEntry;
use App\Module\Audit\Application\AuditTrailPage;
use App\Module\Audit\Application\InvalidAuditTrailQuery;
use App\Module\Audit\Application\ListAuditTrail;
use App\Module\Audit\Application\ReadAuditEvent;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only view of the workspace audit trail. The firewall already denies an
 * anonymous request under /api/; the use cases re-resolve the workspace from
 * the authenticated identity, so the scope never depends on routing alone.
 */
final class AuditTrailController
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/audit-events', name: 'api_v1_audit_events_list', methods: ['GET'])]
    public function list(
        Request $request,
        ListAuditTrail $listAuditTrail,
    ): Response {
        $limit = $request->query->getString('limit');
        if ('' !== $limit && 1 !== preg_match('/^[0-9]{1,3}$/', $limit)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_audit_query');
        }

        $cursor = $request->query->getString('cursor');

        try {
            $page = $listAuditTrail(
                '' === $limit ? null : (int) $limit,
                '' === $cursor ? null : $cursor,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.audit_trail_forbidden');
        } catch (InvalidAuditTrailQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_audit_query');
        }

        return self::jsonPage($page);
    }

    #[Route('/api/v1/audit-events/{id}', name: 'api_v1_audit_events_read', methods: ['GET'])]
    public function read(
        string $id,
        ReadAuditEvent $readAuditEvent,
    ): Response {
        try {
            $entry = $readAuditEvent($id);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.audit_trail_forbidden');
        } catch (AuditEventNotFound) {
            // Identical for an unknown identifier and for one that belongs to
            // another workspace, so the answer never confirms existence.
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.audit_event_not_found');
        }

        return self::json(self::represent($entry));
    }

    private static function jsonPage(AuditTrailPage $page): JsonResponse
    {
        return self::json([
            'items' => array_map(self::represent(...), $page->entries),
            'nextCursor' => $page->nextCursor,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function represent(AuditTrailEntry $entry): array
    {
        return [
            'id' => $entry->id,
            // Microseconds, not ATOM: events written inside one transaction
            // land within the same second, and a reader that cannot see the
            // fraction cannot recover their order.
            'occurredAt' => $entry->occurredAt->format(self::TIMESTAMP_FORMAT),
            'actorId' => $entry->actorId,
            'eventType' => $entry->eventType,
            'entityType' => $entry->entityType,
            'entityId' => $entry->entityId,
            'before' => $entry->before,
            'after' => $entry->after,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse(data: $data, headers: ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
        );
    }
}

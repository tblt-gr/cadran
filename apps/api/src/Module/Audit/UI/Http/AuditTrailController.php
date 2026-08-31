<?php

declare(strict_types=1);

namespace App\Module\Audit\UI\Http;

use App\Module\Audit\Application\AuditTrailAccessDenied;
use App\Module\Audit\Application\AuditTrailPage;
use App\Module\Audit\Application\InvalidAuditTrailQuery;
use App\Module\Audit\Application\ListAuditTrail;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only view of the workspace audit trail. The firewall already denies an
 * anonymous request under /api/; the use case re-resolves the workspace from
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
        #[CurrentUser] ?UserInterface $user,
        ListAuditTrail $listAuditTrail,
    ): Response {
        $limit = $request->query->getString('limit');
        if ('' !== $limit && 1 !== preg_match('/^[0-9]{1,3}$/', $limit)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_audit_query');
        }

        $cursor = $request->query->getString('cursor');

        try {
            $page = $listAuditTrail(
                $user?->getUserIdentifier(),
                '' === $limit ? null : (int) $limit,
                '' === $cursor ? null : $cursor,
            );
        } catch (AuditTrailAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.audit_trail_forbidden');
        } catch (InvalidAuditTrailQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_audit_query');
        }

        return self::json($page);
    }

    private static function json(AuditTrailPage $page): JsonResponse
    {
        return new JsonResponse(
            data: [
                'items' => array_map(static fn ($entry): array => [
                    'id' => $entry->id,
                    // Microseconds, not ATOM: events written inside one
                    // transaction land within the same second, and a reader
                    // that cannot see the fraction cannot recover their order.
                    'occurredAt' => $entry->occurredAt->format(self::TIMESTAMP_FORMAT),
                    'actorId' => $entry->actorId,
                    'eventType' => $entry->eventType,
                    'entityType' => $entry->entityType,
                    'entityId' => $entry->entityId,
                    'before' => $entry->before,
                    'after' => $entry->after,
                ], $page->entries),
                'nextCursor' => $page->nextCursor,
            ],
            headers: ['Cache-Control' => 'no-store'],
        );
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

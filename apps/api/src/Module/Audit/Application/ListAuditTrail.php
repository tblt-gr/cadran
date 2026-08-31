<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

/**
 * Reads one page of the caller's own audit trail. Authorization happens here,
 * not in the controller: the workspace is resolved from the authenticated
 * identity and is the only scope the reader is ever given.
 */
final readonly class ListAuditTrail
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;

    public function __construct(
        private WorkspaceAccess $workspaceAccess,
        private AuditTrailReader $reader,
    ) {
    }

    public function __invoke(?string $userIdentifier, ?int $limit, ?string $cursor): AuditTrailPage
    {
        if (null === $userIdentifier) {
            throw new AuditTrailAccessDenied('An anonymous caller has no audit trail.');
        }

        $workspaceId = $this->workspaceAccess->readableWorkspaceFor($userIdentifier);
        if (null === $workspaceId) {
            throw new AuditTrailAccessDenied('The caller belongs to no workspace.');
        }

        $pageSize = $this->boundedPageSize($limit);
        $after = null === $cursor ? null : AuditTrailCursor::decode($cursor);

        // One extra row answers "is there a next page" without a second count
        // query, and is dropped before the page is returned.
        $entries = $this->reader->readPage($workspaceId, $pageSize + 1, $after);
        if (count($entries) <= $pageSize) {
            return new AuditTrailPage($entries, null);
        }

        $page = array_slice($entries, 0, $pageSize);
        $last = $page[$pageSize - 1];

        return new AuditTrailPage($page, (new AuditTrailCursor($last->occurredAt, $last->id))->encode());
    }

    private function boundedPageSize(?int $limit): int
    {
        if (null === $limit) {
            return self::DEFAULT_PAGE_SIZE;
        }

        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new InvalidAuditTrailQuery(sprintf('The page size must be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        return $limit;
    }
}

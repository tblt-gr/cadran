<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Persistence;

use App\Module\Foundation\Application\ListRevisionRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;

final readonly class DbalListRevisionRepository implements ListRevisionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function lock(WorkspaceScope $workspace, bool $catalogue): string
    {
        // The row is shared by matching writes and their dependent rows. A page and its count
        // therefore describe one revision, without retaining a database snapshot between requests.
        $revision = $catalogue
            ? $this->connection->fetchOne('SELECT revision::text FROM foundation_catalog_revision WHERE singleton = TRUE FOR SHARE')
            : $this->connection->fetchOne('SELECT revision::text FROM foundation_list_revisions WHERE workspace_id = :workspace_id FOR SHARE', ['workspace_id' => $workspace->id]);
        if (!is_string($revision)) {
            throw new \LogicException('The list revision has not been initialized.');
        }

        return $revision;
    }
}

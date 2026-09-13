<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Workspace;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(WorkspaceTimezoneReader::class)]
final readonly class DbalWorkspaceTimezoneReader implements WorkspaceTimezoneReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function timezone(WorkspaceScope $workspace): string
    {
        $timezone = $this->connection->fetchOne(
            'SELECT timezone FROM identity_workspaces WHERE id = :workspace_id',
            ['workspace_id' => $workspace->id],
        );
        if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new WorkspaceAccessDenied('The caller workspace has no valid timezone.');
        }

        return $timezone;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Workspace;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceBaseCurrencyReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(WorkspaceBaseCurrencyReader::class)]
final readonly class DbalWorkspaceBaseCurrencyReader implements WorkspaceBaseCurrencyReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function currency(WorkspaceScope $workspace): AssetCode
    {
        $value = $this->connection->fetchOne(
            'SELECT base_currency FROM identity_workspaces WHERE id = :workspace_id',
            ['workspace_id' => $workspace->id],
        );
        if (!is_string($value)) {
            throw new WorkspaceAccessDenied('The caller workspace has no base currency.');
        }

        return AssetCode::fromString($value);
    }
}

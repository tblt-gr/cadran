<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\ImportedYearReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ImportedYearReader::class)]
final readonly class DbalImportedYearReader implements ImportedYearReader
{
    public function __construct(private Connection $connection)
    {
    }

    /** Reads the `wb:{year}:...` source references of the yearly workbook import (transitional, removed with IMP-007). */
    public function years(WorkspaceScope $workspace): array
    {
        $years = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT substring(source_ref FROM 4 FOR 4)::int AS year FROM transaction_transactions
             WHERE workspace_id = :workspace_id AND source_ref ~ '^wb:[0-9]{4}:' ORDER BY year",
            ['workspace_id' => $workspace->id],
        );

        return array_map(static fn (mixed $year): int => is_numeric($year) ? (int) $year : throw new \UnexpectedValueException('An imported year is not numeric.'), $years);
    }
}

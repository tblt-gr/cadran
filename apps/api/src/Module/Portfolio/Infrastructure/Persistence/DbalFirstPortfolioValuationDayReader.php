<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\FirstPortfolioValuationDayReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FirstPortfolioValuationDayReader::class)]
final readonly class DbalFirstPortfolioValuationDayReader implements FirstPortfolioValuationDayReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?\DateTimeImmutable
    {
        $day = $this->connection->fetchOne(
            'SELECT min(valued_on) FROM portfolio_valuations WHERE workspace_id = :workspace_id AND superseded_at IS NULL AND withdrawn = false AND valued_on <= :as_of',
            ['workspace_id' => $workspace->id, 'as_of' => $asOf->format('Y-m-d')],
        );

        return is_string($day) ? new \DateTimeImmutable($day, new \DateTimeZone('UTC')) : null;
    }
}

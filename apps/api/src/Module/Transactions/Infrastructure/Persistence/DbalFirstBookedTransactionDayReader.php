<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\FirstBookedTransactionDayReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FirstBookedTransactionDayReader::class)]
final readonly class DbalFirstBookedTransactionDayReader implements FirstBookedTransactionDayReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?\DateTimeImmutable
    {
        $day = $this->connection->fetchOne(
            "SELECT min(booked_on) FROM transaction_transactions WHERE workspace_id = :workspace_id AND state = 'BOOKED' AND booked_on <= :as_of",
            ['workspace_id' => $workspace->id, 'as_of' => $asOf->format('Y-m-d')],
        );

        return is_string($day) ? new \DateTimeImmutable($day, new \DateTimeZone('UTC')) : null;
    }
}

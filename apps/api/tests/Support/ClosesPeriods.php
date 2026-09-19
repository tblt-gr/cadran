<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Seeds an active closure straight into the table, without the closing
 * checks: a guarded write is tested against a closed month, not against the
 * rules for getting there, which have their own tests.
 */
trait ClosesPeriods
{
    private function closeMonthInDatabase(Connection $connection, int $year, int $month, string $workspace = WorkspaceFixture::OWN_WORKSPACE, string $author = WorkspaceFixture::OWNER_ID): void
    {
        $connection->insert('account_period_closures', [
            'id' => sprintf('00000000-0000-7000-8000-%012d', $year * 100 + $month),
            'workspace_id' => $workspace,
            'year' => $year,
            'month' => $month,
            'closed_at' => '2026-09-01 10:00:00+00',
            'closed_by' => $author,
            'version' => 1,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises the real upgrade boundary from GOAL-001 data to GOAL-003. The
 * regular migration gate proves empty and released-schema upgrades; this test
 * supplies the rows whose financial backfill and deferred count constraint
 * would otherwise remain unobserved.
 */
final class GoalScheduleMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20260928120000';
    private const string EXACT_GOAL = '00000000-0000-7000-8000-0000000000d1';
    private const string EXACT_VERSION = '00000000-0000-7000-8000-0000000000d2';
    private const string IMPOSSIBLE_GOAL = '00000000-0000-7000-8000-0000000000d3';
    private const string IMPOSSIBLE_VERSION = '00000000-0000-7000-8000-0000000000d4';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $migrationAtHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->runMigration('down');
        $this->migrationAtHead = false;
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        if (!$this->migrationAtHead) {
            $this->runMigration('up');
            $this->migrationAtHead = true;
        }
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItBackfillsExactSchedulesLeavesImpossibleTargetsNullAndEnforcesTwelveParts(): void
    {
        $this->insertAnnualVersion(self::EXACT_GOAL, self::EXACT_VERSION, '2000.00', 2);
        $this->insertAnnualVersion(self::IMPOSSIBLE_GOAL, self::IMPOSSIBLE_VERSION, '0.10', 2);

        $this->runMigration('up');
        $this->migrationAtHead = true;

        self::assertSame('LINEAR', $this->connection->fetchOne('SELECT schedule_mode FROM budget_goal_versions WHERE id = ?', [self::EXACT_VERSION]));
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE goal_version_id = ?', [self::EXACT_VERSION]));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT sum(amount) = 2000 FROM budget_goal_schedule_parts WHERE goal_version_id = ?', [self::EXACT_VERSION]));
        self::assertSame('166.67', $this->connection->fetchOne('SELECT round(amount, 2)::text FROM budget_goal_schedule_parts WHERE goal_version_id = ? AND month_index = 1', [self::EXACT_VERSION]));
        self::assertSame('166.63', $this->connection->fetchOne('SELECT round(amount, 2)::text FROM budget_goal_schedule_parts WHERE goal_version_id = ? AND month_index = 12', [self::EXACT_VERSION]));

        self::assertNull($this->connection->fetchOne('SELECT schedule_mode FROM budget_goal_versions WHERE id = ?', [self::IMPOSSIBLE_VERSION]));
        self::assertSame('0', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE goal_version_id = ?', [self::IMPOSSIBLE_VERSION]));

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('DELETE FROM budget_goal_schedule_parts WHERE goal_version_id = ? AND month_index = 12', [self::EXACT_VERSION]);
            $this->connection->commit();
            self::fail('The migrated schedule must retain exactly twelve parts.');
        } catch (DbalException) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->addToAssertionCount(1);
        }
        self::assertSame('12', $this->scalar('SELECT count(*) FROM budget_goal_schedule_parts WHERE goal_version_id = ?', [self::EXACT_VERSION]));
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsScalar($value);

        return (string) $value;
    }

    private function runMigration(string $direction): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute([
            'versions' => [self::MIGRATION],
            '--'.$direction => true,
            '--no-interaction' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        // Doctrine freezes an executed migration instance. Rebooting mirrors
        // the separate console processes used by the migration gate and gives
        // the opposite direction a fresh instance.
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
    }

    private function insertAnnualVersion(string $goalId, string $versionId, string $target, int $scale): void
    {
        $this->connection->insert('budget_goals', ['id' => $goalId, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Existing annual goal', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => 'ACTIVE', 'created_at' => '2026-01-01 12:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID, 'closed_at' => null, 'version' => 1]);
        $this->connection->insert('budget_goal_versions', ['id' => $versionId, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'goal_id' => $goalId, 'version_no' => 1, 'effective_from' => '2026-01', 'year' => 2026, 'target_amount' => $target, 'target_scale' => $scale, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => null, 'reason' => null, 'created_at' => '2026-01-01 12:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID]);
    }
}

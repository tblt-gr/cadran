<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountOverviewOrderMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261022120000';
    private const string GROUP = '00000000-0000-7000-8000-0000000000b1';
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
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name = 'account_overview_positions'"));
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if (!$this->migrationAtHead) {
            $this->runMigration('up');
        }
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testExistingAccountsKeepTheirLabelOrderInsideEachGroupAndWorkspace(): void
    {
        $this->connection->insert('account_groups', [
            'id' => self::GROUP,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'label' => 'Épargne',
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-10-01 12:00:00+00',
            'updated_at' => '2026-10-01 12:00:00+00',
        ]);
        $zulu = $this->insert('01', 'Zulu', self::GROUP);
        $alpha = $this->insert('02', 'Alpha', self::GROUP);
        $loose = $this->insert('03', 'Mango', null);
        $foreign = $this->insert('04', 'Alpha', null, WorkspaceFixture::OTHER_WORKSPACE);

        $this->runMigration('up');
        $this->migrationAtHead = true;

        $positions = $this->connection->fetchAllAssociative('SELECT account_id, group_key, position FROM account_overview_positions');
        $byAccount = [];
        foreach ($positions as $row) {
            self::assertIsString($row['account_id']);
            self::assertIsString($row['group_key']);
            self::assertTrue(is_int($row['position']));
            $byAccount[$row['account_id']] = [$row['group_key'], $row['position']];
        }
        self::assertSame([self::GROUP, 1], $byAccount[$alpha]);
        self::assertSame([self::GROUP, 2], $byAccount[$zulu]);
        self::assertSame(['00000000-0000-0000-0000-000000000000', 1], $byAccount[$loose]);
        self::assertSame(['00000000-0000-0000-0000-000000000000', 1], $byAccount[$foreign]);
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM account_overview_orders'));
    }

    private function insert(string $suffix, string $label, ?string $groupId, string $workspace = WorkspaceFixture::OWN_WORKSPACE): string
    {
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => 'SAVINGS',
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'version' => 1,
            'created_at' => '2026-10-01 12:00:00+00',
            'updated_at' => '2026-10-01 12:00:00+00',
            'primary_group_id' => $groupId,
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);

        return $id;
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
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountGroupColorMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261021120000';
    // Later migration that reads the color column; it is rolled back around this one.
    private const string DEPENDENT_MIGRATION = 'DoctrineMigrations\\Version20261027120000';
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
        $this->runMigration('down', self::DEPENDENT_MIGRATION);
        $this->runMigration('down');
        $this->migrationAtHead = false;
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'account_groups' AND column_name = 'color'"));
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if (!$this->migrationAtHead) {
            $this->runMigration('up');
        }
        $this->runMigration('up', self::DEPENDENT_MIGRATION);
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testPalettePreservesOnlyActiveRootsInEachWorkspace(): void
    {
        $first = $this->insert('b1', 'Zulu', 0);
        $second = $this->insert('b2', 'Alpha', 1);
        $third = $this->insert('b3', 'Beta', 1);
        $fourth = $this->insert('b4', 'Gamma', 1);
        $fifth = $this->insert('b5', 'Last', 2);
        $archived = $this->insert('b6', 'Archived', 0, archived: true);
        $child = $this->insert('b7', 'Child', 0, parentId: $first);
        $foreign = $this->insert('b8', 'Foreign', 0, workspace: WorkspaceFixture::OTHER_WORKSPACE);
        $this->runMigration('up');
        $this->migrationAtHead = true;
        $colors = $this->connection->fetchAllKeyValue('SELECT id, color FROM account_groups');
        self::assertSame('#486DF0', $colors[$first]);
        self::assertSame('#803078', $colors[$second]);
        self::assertSame('#58B8C0', $colors[$third]);
        self::assertSame('#F0A8D8', $colors[$fourth]);
        self::assertSame('#486DF0', $colors[$fifth]);
        self::assertSame('#486DF0', $colors[$foreign]);
        self::assertNull($colors[$archived]);
        self::assertNull($colors[$child]);
        self::assertEquals(1, $this->connection->fetchOne('SELECT version FROM account_groups WHERE id = ?', [$first]));
        foreach (['#aabbcc', '#123', 'url(https://example.org)', '#12345678'] as $invalid) {
            try {
                $this->connection->update('account_groups', ['color' => $invalid], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $first]);
                self::fail('The database must reject noncanonical colors.');
            } catch (DbalException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('#486DF0', $this->connection->fetchOne('SELECT color FROM account_groups WHERE id = ?', [$first]));
    }

    private function insert(string $suffix, string $label, int $order, bool $archived = false, ?string $parentId = null, string $workspace = WorkspaceFixture::OWN_WORKSPACE): string
    {
        $id = '00000000-0000-7000-8000-0000000000'.$suffix;
        $this->connection->insert('account_groups', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'sort_order' => $order,
            'parent_id' => $parentId,
            'depth' => null === $parentId ? 1 : 2,
            'version' => 1,
            'created_at' => '2026-10-01 12:00:00+00',
            'updated_at' => '2026-10-01 12:00:00+00',
            'archived_at' => $archived ? '2026-10-02 12:00:00+00' : null,
        ]);

        return $id;
    }

    private function runMigration(string $direction, string $migration = self::MIGRATION): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute([
            'versions' => [$migration],
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

<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RecapAccountSelectionMigrationTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $atHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $this->connect();
        $this->fixture->reset();
        $this->migrate('down');
        $this->atHead = false;
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if (!$this->atHead) {
            $this->migrate('up');
        }
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testMigrationRetainsFlowPreferencesAndVersionAndStartsAccountDefaults(): void
    {
        $this->connection->insert('reporting_recap_preferences', [
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'visible_category_ids' => '[]', 'visible_axes' => '["FIXED"]',
            'version' => 7, 'updated_at' => '2026-09-01 12:00:00+00',
        ]);
        $this->migrate('up');
        $this->atHead = true;
        $row = $this->connection->fetchAssociative('SELECT visible_category_ids, visible_axes, version, visible_account_ids FROM reporting_recap_preferences WHERE workspace_id = :workspace_id', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE]);
        self::assertIsArray($row);
        self::assertSame('[]', $row['visible_category_ids']);
        self::assertSame('["FIXED"]', $row['visible_axes']);
        self::assertSame(7, $row['version']);
        self::assertNull($row['visible_account_ids']);
    }

    private function migrate(string $direction): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => ['DoctrineMigrations\\Version20261022130000'], '--'.$direction => true, '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->connect();
    }

    private function connect(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
    }
}

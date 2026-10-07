<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LifeInsuranceOperationsMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261006120000';
    private const string SWITCH_MIGRATION = 'DoctrineMigrations\Version20261007120000';
    private Connection $connection;
    private bool $atHead = true;
    private bool $atSwitchHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        (new WorkspaceFixture($connection))->reset();
    }

    protected function tearDown(): void
    {
        if (!$this->atHead) {
            $this->executeMigration('up');
        }
        if (!$this->atSwitchHead) {
            $this->executeMigration('up', self::SWITCH_MIGRATION);
        }
        parent::tearDown();
    }

    public function testTheMigrationCanBeReversedAndReappliedWithScopedForeignKeysAndExactDecimals(): void
    {
        $this->executeMigration('down', self::SWITCH_MIGRATION);
        $this->atSwitchHead = false;
        $this->executeMigration('down');
        $this->atHead = false;
        self::assertFalse($this->connection->fetchOne("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'portfolio_operation_legs')"));
        self::assertFalse($this->connection->fetchOne("SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_name = 'portfolio_operations' AND column_name = 'withheld_income_tax_value')"));
        $this->executeMigration('up');
        $this->atHead = true;
        $this->executeMigration('up', self::SWITCH_MIGRATION);
        $this->atSwitchHead = true;
        $constraint = $this->connection->fetchOne("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'portfolio_operations'::regclass AND conname = 'portfolio_operations_type_valid'");
        self::assertIsString($constraint);
        self::assertStringContainsString('SWITCH', $constraint);
        $columns = $this->connection->fetchAllAssociative("SELECT column_name, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_name = 'portfolio_operation_legs' AND data_type = 'numeric' ORDER BY column_name");
        self::assertCount(4, $columns);
        foreach ($columns as $column) {
            self::assertSame(50, $column['numeric_precision']);
            self::assertSame(24, $column['numeric_scale']);
        }
        $keys = $this->connection->fetchFirstColumn("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'portfolio_operation_legs'::regclass AND contype = 'f' ORDER BY conname DESC");
        self::assertCount(2, $keys);
        self::assertIsString($keys[0]);
        self::assertIsString($keys[1]);
        self::assertStringContainsString('(operation_id, workspace_id)', $keys[0]);
        self::assertStringContainsString('(instrument_id, workspace_id)', $keys[1]);
    }

    private function executeMigration(string $direction, string $migration = self::MIGRATION): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [$migration], '--'.$direction => true, '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }
}

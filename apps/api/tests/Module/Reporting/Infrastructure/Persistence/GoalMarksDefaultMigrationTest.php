<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Infrastructure\Persistence;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GoalMarksDefaultMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261114120000';
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $atHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->migrate('down');
        $this->atHead = false;
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        if (!$this->atHead) {
            $this->migrate('up');
        }
        parent::tearDown();
    }

    public function testUpgradeAndRollbackChangeOnlyTheDefaultAndPreserveBothStoredChoices(): void
    {
        $this->insert(WorkspaceFixture::OWN_WORKSPACE, true);
        $this->insert(WorkspaceFixture::OTHER_WORKSPACE, false);
        $before = $this->rows();
        self::assertSame('true', $this->defaultExpression());

        $this->migrate('up');
        $this->atHead = true;

        self::assertSame('false', $this->defaultExpression());
        self::assertSame($before, $this->rows());

        $this->migrate('down');
        $this->atHead = false;

        self::assertSame('true', $this->defaultExpression());
        self::assertSame($before, $this->rows());
    }

    private function insert(string $workspace, bool $goalMarks): void
    {
        $this->connection->insert('reporting_annual_report_preferences', [
            'workspace_id' => $workspace,
            'columns' => '["budgetExpenses","cashIncome"]',
            'incomplete_months' => 'include',
            'goal_marks' => $goalMarks,
            'version' => 3,
            'updated_at' => '2026-11-11 12:00:00+00',
        ], ['goal_marks' => ParameterType::BOOLEAN]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM reporting_annual_report_preferences ORDER BY workspace_id');
    }

    private function defaultExpression(): string
    {
        $default = $this->connection->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'reporting_annual_report_preferences' AND column_name = 'goal_marks'");
        self::assertIsString($default);

        return $default;
    }

    private function migrate(string $direction): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::MIGRATION], '--'.$direction => true, '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
    }
}

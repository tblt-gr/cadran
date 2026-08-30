<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Console;

use App\Module\Identity\UI\Console\ProvisionInitialOwnerCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProvisionInitialOwnerCommandTest extends KernelTestCase
{
    private Connection $connection;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        if (false === getenv('DATABASE_URL')) {
            if (false !== getenv('CI')) {
                self::fail('DATABASE_URL must be set in CI; PostgreSQL integration tests may not be skipped there.');
            }

            self::markTestSkipped('This PostgreSQL integration test requires DATABASE_URL.');
        }

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->databaseReady = true;
        $this->clearIdentityData();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearIdentityData();
        }

        parent::tearDown();
    }

    public function testItPersistsOneOwnerWorkspaceAndRejectsAReplay(): void
    {
        $command = self::getContainer()->get(ProvisionInitialOwnerCommand::class);
        self::assertInstanceOf(ProvisionInitialOwnerCommand::class, $command);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'owner@example.test',
            'workspace-name' => 'Household',
            'display-name' => 'Owner',
            'base-currency' => 'EUR',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_users'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_workspaces'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_workspace_memberships WHERE role = ?', ['OWNER']));
        self::assertSame('Europe/Paris', $this->connection->fetchOne('SELECT timezone FROM identity_workspaces'));
        self::assertSame('EUR', $this->connection->fetchOne('SELECT base_currency FROM identity_workspaces'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_initial_provisionings'));

        $replayExitCode = $tester->execute([
            'email' => 'second-owner@example.test',
            'workspace-name' => 'Second household',
            'display-name' => 'Second owner',
            'base-currency' => 'EUR',
        ]);

        self::assertSame(Command::FAILURE, $replayExitCode);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_workspaces'));
    }

    public function testItReportsAnInfrastructureFailureWithADistinctExitCodeAndNoLeak(): void
    {
        $this->connection->insert('identity_users', [
            'id' => '00000000-0000-7000-8000-0000000000ff',
            'email' => 'owner@example.test',
            'display_name' => 'Existing',
            'created_at' => '2026-08-30 12:00:00.000000+00',
        ]);

        $command = self::getContainer()->get(ProvisionInitialOwnerCommand::class);
        self::assertInstanceOf(ProvisionInitialOwnerCommand::class, $command);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'owner@example.test',
            'workspace-name' => 'Household',
            'display-name' => 'Owner',
            'base-currency' => 'EUR',
        ]);

        self::assertSame(3, $exitCode);
        self::assertNotSame(Command::FAILURE, $exitCode);
        self::assertStringNotContainsString('owner@example.test', $tester->getDisplay());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_workspaces'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_initial_provisionings'));
    }

    private function clearIdentityData(): void
    {
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}

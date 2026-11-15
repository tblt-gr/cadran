<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Console;

use App\Module\Identity\Application\ResetOwnerPassword;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\UI\Console\ResetOwnerPasswordCommand;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetOwnerPasswordCommandTest extends KernelTestCase
{
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string CURRENT = 'correct horse battery staple';
    private const string NEW = 'a different long passphrase';

    private Connection $connection;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->databaseReady = true;
        $this->clear();
    }

    protected function tearDown(): void
    {
        putenv('CADRAN_RESET_PASSWORD');

        if ($this->databaseReady) {
            $this->clear();
        }

        parent::tearDown();
    }

    public function testFromEnvReplacesThePasswordAndAuditsOnce(): void
    {
        $this->provisionOwner();
        $before = $this->hash();
        putenv('CADRAN_RESET_PASSWORD='.self::NEW);

        $tester = $this->execute(['--from-env' => true], interactive: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('every session was closed', $tester->getDisplay());
        self::assertStringNotContainsString(self::NEW, $tester->getDisplay());
        self::assertNotSame($before, $this->hash());
        self::assertSame(
            1,
            $this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type = 'user.password_reset'"),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM sessions'));
    }

    public function testTheInteractivePromptReplacesThePassword(): void
    {
        $this->provisionOwner();
        $before = $this->hash();

        $tester = $this->execute([], inputs: [self::NEW, self::NEW]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNotSame($before, $this->hash());
        self::assertStringNotContainsString(self::NEW, $tester->getDisplay());
    }

    public function testTwoDifferentEntriesAreRefused(): void
    {
        $this->provisionOwner();
        $before = $this->hash();

        $tester = $this->execute([], inputs: [self::NEW, 'another long passphrase!']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The two entries differ.', $tester->getDisplay());
        self::assertSame($before, $this->hash());
    }

    public function testAWeakPasswordIsRefusedWithoutEchoingIt(): void
    {
        $this->provisionOwner();
        $before = $this->hash();
        putenv('CADRAN_RESET_PASSWORD=Zz9!x');

        $tester = $this->execute(['--from-env' => true], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('between 12 and 128', $tester->getDisplay());
        self::assertStringNotContainsString('Zz9!x', $tester->getDisplay());
        self::assertSame($before, $this->hash());
    }

    public function testACommonPasswordIsRefused(): void
    {
        $this->provisionOwner();
        $before = $this->hash();
        putenv('CADRAN_RESET_PASSWORD=Pornographic');

        $tester = $this->execute(['--from-env' => true], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($before, $this->hash());
    }

    public function testTheCurrentPasswordIsRefused(): void
    {
        $this->provisionOwner();
        $before = $this->hash();
        putenv('CADRAN_RESET_PASSWORD='.self::CURRENT);

        $tester = $this->execute(['--from-env' => true], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must differ', $tester->getDisplay());
        self::assertSame($before, $this->hash());
    }

    public function testWithoutAnOwnerItPointsToMakeSetup(): void
    {
        putenv('CADRAN_RESET_PASSWORD='.self::NEW);

        $tester = $this->execute(['--from-env' => true], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('make setup', $tester->getDisplay());
    }

    public function testFromEnvIsRefusedInProd(): void
    {
        $this->provisionOwner();
        $before = $this->hash();
        putenv('CADRAN_RESET_PASSWORD='.self::NEW);
        $service = self::getContainer()->get(ResetOwnerPassword::class);
        self::assertInstanceOf(ResetOwnerPassword::class, $service);
        $tester = new CommandTester(new ResetOwnerPasswordCommand($service, 'prod'));

        $tester->execute(['--from-env' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($before, $this->hash());
    }

    public function testANonInteractiveRunWithoutFromEnvIsRefused(): void
    {
        $this->provisionOwner();

        $tester = $this->execute([], interactive: false);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $inputs
     */
    private function execute(array $arguments, bool $interactive = true, array $inputs = []): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('cadran:identity:reset-password'));
        if ([] !== $inputs) {
            $tester->setInputs($inputs);
        }
        $tester->execute($arguments, ['interactive' => $interactive]);

        return $tester;
    }

    private function hash(): string
    {
        $hash = $this->connection->fetchOne('SELECT password_hash FROM identity_users');
        self::assertIsString($hash);

        return $hash;
    }

    private function provisionOwner(): void
    {
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $createdAt = '2026-08-30 12:00:00.000000+00';

        $this->connection->insert('identity_users', [
            'id' => self::USER_ID,
            'email' => 'owner@example.test',
            'display_name' => 'Owner',
            'created_at' => $createdAt,
            'password_hash' => $hasher->hash(PlainPassword::fromString(self::CURRENT)),
            'disabled_at' => null,
        ]);
        $this->connection->insert('identity_workspaces', [
            'id' => self::WORKSPACE_ID,
            'name' => 'Household',
            'timezone' => 'Europe/Paris',
            'base_currency' => 'EUR',
            'created_at' => $createdAt,
        ]);
        $this->connection->insert('identity_workspace_memberships', [
            'id' => '00000000-0000-7000-8000-0000000000b1',
            'workspace_id' => self::WORKSPACE_ID,
            'user_id' => self::USER_ID,
            'role' => 'OWNER',
            'created_at' => $createdAt,
        ]);
    }

    private function clear(): void
    {
        $this->connection->executeStatement('DELETE FROM sessions');
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}

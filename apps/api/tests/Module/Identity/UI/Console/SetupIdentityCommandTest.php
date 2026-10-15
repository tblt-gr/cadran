<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Console;

use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\DefineInitialPassword;
use App\Module\Identity\Application\ProvisionInitialWorkspace;
use App\Module\Identity\Infrastructure\Security\FilePasswordList;
use App\Module\Identity\UI\Console\SetupIdentityCommand;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The one-time interactive setup: it creates the owner, their workspace and
 * their password in a single run, and refuses to run twice.
 */
final class SetupIdentityCommandTest extends KernelTestCase
{
    private const string EMAIL = 'owner@example.test';
    private const string PASSWORD = 'correct horse battery staple';

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
        $this->clearIdentityData();
    }

    protected function tearDown(): void
    {
        foreach (['EMAIL', 'DISPLAY_NAME', 'WORKSPACE_NAME', 'PASSWORD', 'BASE_CURRENCY'] as $name) {
            putenv('CADRAN_SETUP_'.$name);
        }

        if ($this->databaseReady) {
            $this->clearIdentityData();
        }

        parent::tearDown();
    }

    public function testItCreatesTheOwnerTheWorkspaceAndThePasswordInOneRun(): void
    {
        $tester = $this->runSetup([self::EMAIL, 'Marie Dupont', 'Household', 'EUR', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $user = $this->connection->fetchAssociative('SELECT email, display_name, password_hash FROM identity_users');
        self::assertIsArray($user);
        self::assertSame(self::EMAIL, $user['email']);
        self::assertSame('Marie Dupont', $user['display_name']);
        self::assertIsString($user['password_hash']);
        self::assertNotSame('', $user['password_hash']);

        $workspace = $this->connection->fetchAssociative('SELECT name, base_currency FROM identity_workspaces');
        self::assertIsArray($workspace);
        self::assertSame('Household', $workspace['name']);
        self::assertSame('EUR', $workspace['base_currency']);

        $role = $this->connection->fetchOne('SELECT role FROM identity_workspace_memberships');
        self::assertSame('OWNER', $role);
    }

    public function testNeitherPasswordReachesTheTerminalOutput(): void
    {
        $tester = $this->runSetup([self::EMAIL, 'Marie Dupont', 'Household', 'EUR', self::PASSWORD, self::PASSWORD]);

        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testItRefusesToRunOnceProvisioningIsComplete(): void
    {
        $first = $this->runSetup([self::EMAIL, 'Marie Dupont', 'Household', 'EUR', self::PASSWORD, self::PASSWORD]);
        self::assertSame(Command::SUCCESS, $first->getStatusCode());

        $second = $this->runSetup(['second@example.test', 'Someone Else', 'Other', 'EUR', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::FAILURE, $second->getStatusCode());
        self::assertStringContainsString('already been completed', $second->getDisplay());
        self::assertSame(1, $this->userCount());
    }

    public function testItFinishesAnInterruptedRunByAskingOnlyForThePassword(): void
    {
        // A run that died between provisioning and the password write leaves an
        // owner with no hash. Refusing to resume would strand the install on the
        // unauthenticated first-run endpoint, which is the exposure setup exists
        // to avoid.
        $this->provisionOwnerWithoutPassword();

        $tester = $this->runSetup([self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $hash = $this->connection->fetchOne('SELECT password_hash FROM identity_users');
        self::assertIsString($hash);
        self::assertNotSame('', $hash);
        self::assertSame(1, $this->userCount());
    }

    public function testTwoDifferentPasswordsAbortWithoutWriting(): void
    {
        $tester = $this->runSetup([self::EMAIL, 'Marie Dupont', 'Household', 'EUR', self::PASSWORD, 'a different long passphrase']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(0, $this->userCount());
    }

    public function testANonInteractiveRunIsRefusedRatherThanGuessing(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('cadran:identity:setup'));

        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(0, $this->userCount());
    }

    public function testFromEnvProvisionsTheOwnerWithoutPrompting(): void
    {
        $this->setSetupEnvironment();

        $tester = $this->runFromEnv();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $user = $this->connection->fetchAssociative('SELECT email, display_name, password_hash FROM identity_users');
        self::assertIsArray($user);
        self::assertSame(self::EMAIL, $user['email']);
        self::assertSame('Marie Dupont', $user['display_name']);
        self::assertNotEmpty($user['password_hash']);
        self::assertSame('Household', $this->connection->fetchOne('SELECT name FROM identity_workspaces'));
        self::assertSame('OWNER', $this->connection->fetchOne('SELECT role FROM identity_workspace_memberships'));
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testFromEnvDefaultsTheBaseCurrencyToEuro(): void
    {
        $this->setSetupEnvironment();

        $this->runFromEnv();

        self::assertSame('EUR', $this->connection->fetchOne('SELECT base_currency FROM identity_workspaces'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredVariables(): iterable
    {
        yield 'email' => ['CADRAN_SETUP_EMAIL'];
        yield 'display name' => ['CADRAN_SETUP_DISPLAY_NAME'];
        yield 'workspace name' => ['CADRAN_SETUP_WORKSPACE_NAME'];
        yield 'password' => ['CADRAN_SETUP_PASSWORD'];
    }

    #[DataProvider('requiredVariables')]
    public function testFromEnvNamesTheMissingVariableAndWritesNothing(string $missing): void
    {
        $this->setSetupEnvironment();
        putenv($missing);

        $tester = $this->runFromEnv();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($missing, $tester->getDisplay());
        self::assertSame(0, $this->userCount());
    }

    public function testFromEnvRejectsAWeakPasswordWithoutEchoingIt(): void
    {
        $this->setSetupEnvironment();
        // A value no message or variable name can contain by accident.
        $weak = 'Zz9!x';
        putenv('CADRAN_SETUP_PASSWORD='.$weak);

        $tester = $this->runFromEnv();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringNotContainsString($weak, $tester->getDisplay());
        self::assertSame(0, $this->userCount());
    }

    public function testFromEnvRejectsACommonPasswordBeforeProvisioningAnything(): void
    {
        $this->setSetupEnvironment();
        putenv('CADRAN_SETUP_PASSWORD=Pornographic');

        $tester = $this->runFromEnv();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringNotContainsString('Pornographic', $tester->getDisplay());
        self::assertSame(0, $this->userCount(), 'A refused password must not leave a half-provisioned owner behind.');
    }

    public function testFromEnvFinishesAnInterruptedRunWithOnlyThePassword(): void
    {
        // The completePassword path: identity rows exist without a hash, so only the
        // password variable is read and no second owner is created.
        $this->provisionOwnerWithoutPassword();
        putenv('CADRAN_SETUP_PASSWORD='.self::PASSWORD);

        $tester = $this->runFromEnv();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $hash = $this->connection->fetchOne('SELECT password_hash FROM identity_users');
        self::assertIsString($hash);
        self::assertNotSame('', $hash);
        self::assertSame(1, $this->userCount());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testFromEnvRejectsAnInvalidEmail(): void
    {
        $this->setSetupEnvironment();
        putenv('CADRAN_SETUP_EMAIL=not-an-email');

        $tester = $this->runFromEnv();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(0, $this->userCount());
    }

    public function testFromEnvIsRefusedOutsideDevAndTest(): void
    {
        $this->setSetupEnvironment();
        $container = self::getContainer();
        $command = new SetupIdentityCommand(
            self::service($container, ProvisionInitialWorkspace::class),
            self::service($container, DefineInitialPassword::class),
            self::service($container, AuthenticationUserRepository::class),
            new FilePasswordList(),
            'prod',
        );
        $tester = new CommandTester($command);

        $tester->execute(['--from-env' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->userCount());
    }

    public function testFromEnvReportsAnAlreadyProvisionedInstall(): void
    {
        $this->setSetupEnvironment();
        self::assertSame(Command::SUCCESS, $this->runFromEnv()->getStatusCode());

        $second = $this->runFromEnv();

        self::assertSame(Command::FAILURE, $second->getStatusCode());
        self::assertStringContainsString('already been completed', $second->getDisplay());
        self::assertSame(1, $this->userCount());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function setSetupEnvironment(): void
    {
        putenv('CADRAN_SETUP_EMAIL='.self::EMAIL);
        putenv('CADRAN_SETUP_DISPLAY_NAME=Marie Dupont');
        putenv('CADRAN_SETUP_WORKSPACE_NAME=Household');
        putenv('CADRAN_SETUP_PASSWORD='.self::PASSWORD);
        putenv('CADRAN_SETUP_BASE_CURRENCY');
    }

    private function runFromEnv(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('cadran:identity:setup'));
        $tester->execute(['--from-env' => true], ['interactive' => false]);

        return $tester;
    }

    /**
     * The state an interrupted run leaves behind: identity rows committed, no
     * password hash.
     */
    private function provisionOwnerWithoutPassword(): void
    {
        $createdAt = '2026-08-30 12:00:00.000000+00';
        $userId = '00000000-0000-7000-8000-000000000001';
        $workspaceId = '00000000-0000-7000-8000-0000000000a1';

        $this->connection->insert('identity_users', [
            'id' => $userId,
            'email' => self::EMAIL,
            'display_name' => 'Owner',
            'created_at' => $createdAt,
            'password_hash' => null,
            'disabled_at' => null,
        ]);
        $this->connection->insert('identity_workspaces', [
            'id' => $workspaceId,
            'name' => 'Household',
            'timezone' => 'Europe/Paris',
            'base_currency' => 'EUR',
            'created_at' => $createdAt,
        ]);
        $this->connection->insert('identity_workspace_memberships', [
            'id' => '00000000-0000-7000-8000-0000000000b1',
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'OWNER',
            'created_at' => $createdAt,
        ]);
        $this->connection->executeStatement('INSERT INTO identity_initial_provisionings (id) VALUES (true)');
    }

    private function userCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM identity_users');
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * @param list<string> $answers
     */
    private function runSetup(array $answers): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('cadran:identity:setup'));
        $tester->setInputs($answers);
        $tester->execute([]);

        return $tester;
    }

    private function clearIdentityData(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}

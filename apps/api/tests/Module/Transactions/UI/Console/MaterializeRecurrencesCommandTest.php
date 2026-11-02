<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Console;

use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaterializeRecurrencesCommandTest extends KernelTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-11-03T09:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testItCreatesTheMonthAndPrintsCountsOnly(): void
    {
        $this->seedAutoRecurrence();

        $tester = $this->tester();
        $status = $tester->execute(['email' => WorkspaceFixture::OWNER_EMAIL]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('1 recurrence(s) examined: 1 transaction(s) created, 0 booked', $display);
        self::assertStringNotContainsString('Loyer', $display);
        self::assertStringNotContainsString('850', $display);
        self::assertCount(1, $this->connection->fetchAllAssociative('SELECT id FROM transaction_transactions'));
    }

    public function testItActsAsTheProvisionedOwnerWithoutAnEmail(): void
    {
        $status = $this->tester()->execute([]);

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testAnUnknownOwnerFailsWithoutEchoingTheEmail(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['email' => 'nobody@example.test']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringNotContainsString('nobody@example.test', $tester->getDisplay());
    }

    public function testADisabledOwnerIsRefused(): void
    {
        $this->connection->executeStatement("UPDATE identity_users SET disabled_at = '2026-10-01 10:00:00+00' WHERE email = ?", [WorkspaceFixture::OWNER_EMAIL]);

        self::assertSame(Command::FAILURE, $this->tester()->execute(['email' => WorkspaceFixture::OWNER_EMAIL]));
    }

    public function testAnOwnerWithoutPasswordIsRefused(): void
    {
        $this->connection->executeStatement('UPDATE identity_users SET password_hash = NULL WHERE email = ?', [WorkspaceFixture::OWNER_EMAIL]);

        self::assertSame(Command::FAILURE, $this->tester()->execute(['email' => WorkspaceFixture::OWNER_EMAIL]));
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('cadran:transactions:materialize-recurrences'));
    }

    private function seedAutoRecurrence(): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => self::ACCOUNT, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Compte', 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => 'f',
            'include_in_emergency_fund' => 'f', 'opened_on' => '2024-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ]);
        $this->connection->executeStatement(
            'INSERT INTO transaction_recurrences (id, workspace_id, account_id, label, counterparty, expected_amount_value, expected_amount_scale, asset_code, amount_tolerance_value, amount_tolerance_scale, interval_kind, day_of_period, next_expected_on, confirmed_at, version, created_at, updated_at, auto_create) '
            ."VALUES ('00000000-0000-7000-8000-0000000000e1', ?, ?, 'Loyer', NULL, -850, 2, 'EUR', 0, 2, 'MONTHLY', 5, '2026-11-05', now(), 1, now(), now(), true)",
            [WorkspaceFixture::OWN_WORKSPACE, self::ACCOUNT],
        );
        $this->connection->executeStatement(
            "INSERT INTO transaction_recurrence_occurrences (id, workspace_id, recurrence_id, expected_on, expected_amount_value, expected_amount_scale, amount_tolerance_value, amount_tolerance_scale, status) VALUES ('00000000-0000-7000-8000-0000000000e8', ?, '00000000-0000-7000-8000-0000000000e1', '2026-11-05', -850, 2, 0, 2, 'EXPECTED')",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Console;

use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CaptureSnapshotsCommandTest extends KernelTestCase
{
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
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

    public function testItReportsCountsOnlyAndActsAsTheProvisionedOwnerWithoutAnEmail(): void
    {
        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('0 month(s) captured, 0 skipped', $tester->getDisplay());
    }

    public function testAnUnknownOwnerFailsWithoutEchoingTheEmail(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['email' => 'nobody@example.test']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringNotContainsString('nobody@example.test', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('cadran:reporting:capture-snapshots'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Infrastructure\Persistence;

use App\Module\Reporting\Infrastructure\Persistence\DbalMetricPolicyRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MetricPolicyLockTest extends KernelTestCase
{
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
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testASecondConnectionWaitsForTheWorkspaceLockUntilTheFirstCommits(): void
    {
        $own = WorkspaceFixture::own();
        $other = DriverManager::getConnection($this->connection->getParams());
        $first = new DbalMetricPolicyRepository($this->connection);
        $second = new DbalMetricPolicyRepository($other);

        try {
            $this->connection->beginTransaction();
            $first->lock($own);

            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            try {
                $second->lock($own);
                self::fail('A second activation must wait for the one in flight.');
            } catch (DriverException) {
                self::addToAssertionCount(1);
            }
            $other->rollBack();

            // Another workspace is never blocked.
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            $second->lock(WorkspaceFixture::other());
            $other->rollBack();

            // Once the first transaction ends, the second proceeds.
            $this->connection->commit();
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            $second->lock($own);
            $other->rollBack();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $other->close();
        }
    }
}

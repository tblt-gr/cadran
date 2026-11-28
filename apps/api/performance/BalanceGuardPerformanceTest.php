<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An interactive write dated today on a guarded account holding 1 500 daily balances and 20 000 movements.
 * The balance floor reads only what can anchor today, so the account's age must not weigh on the write.
 * The median is always printed; it only fails the run with PERF_ASSERT=1.
 */
final class BalanceGuardPerformanceTest extends WebTestCase
{
    private const float BUDGET_MS = 100.0;
    private const int SNAPSHOTS = 1_500;
    private const int MOVEMENTS = 20_000;
    private const int WRITES = 20;

    public function testWritesDatedTodayStayWithinTheirBudget(): void
    {
        WorkspaceFixture::requireDatabase();
        $client = self::createClient(['debug' => false]);
        $client->disableReboot();
        $client->catchExceptions(false);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $fixture = new WorkspaceFixture($connection);
        $fixture->reset();

        try {
            $pool = self::getContainer()->get('cache.rate_limiter');
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
            $hasher = self::getContainer()->get(PasswordHasher::class);
            self::assertInstanceOf(PasswordHasher::class, $hasher);
            $fixture->seed($hasher);
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
            $account = $this->seedAccount($connection, $today);
            $this->signIn($client);

            $samples = [];
            for ($index = 0; $index < self::WRITES; ++$index) {
                $samples[] = $this->timedWrite($client, $account, $today);
            }

            $median = (new LatencyStatistics($samples))->percentile(50);
            fwrite(STDERR, sprintf("\nbalance guard: median write %.1f ms over %d writes\n", $median, self::WRITES));
            if ('1' === getenv('PERF_ASSERT')) {
                self::assertLessThan(self::BUDGET_MS, $median);
            }
        } finally {
            $fixture->reset();
        }
    }

    private function seedAccount(Connection $connection, \DateTimeImmutable $today): string
    {
        (new SyntheticVolumeSeeder($connection))->seed(WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWNER_ID, $today, 40_000, 5);
        $account = $connection->fetchOne(
            "SELECT id FROM account_financial_accounts WHERE workspace_id = ? AND label = 'Compte courant principal'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsString($account);

        $held = $this->movementCount($connection, $account);
        $connection->executeStatement(
            'UPDATE transaction_transactions SET account_id = ? WHERE id IN (SELECT id FROM transaction_transactions'
            ." WHERE workspace_id = ? AND account_id <> ? AND state = 'BOOKED' AND nature IN ('EXPENSE', 'INCOME') ORDER BY id LIMIT ?)",
            [$account, WorkspaceFixture::OWN_WORKSPACE, $account, max(0, self::MOVEMENTS - $held)],
        );
        self::assertGreaterThanOrEqual(self::MOVEMENTS, $this->movementCount($connection, $account));

        $connection->executeStatement('DELETE FROM account_balance_snapshots WHERE workspace_id = ? AND account_id = ?', [WorkspaceFixture::OWN_WORKSPACE, $account]);
        $connection->executeStatement(
            'INSERT INTO account_balance_snapshots (id, workspace_id, account_id, as_of, amount_value, amount_literal, amount_asset, source, reconciliation_status, version, recorded_at, recorded_by)'
            ." SELECT gen_random_uuid(), ?, ?, CAST(? AS date) - day, 1000000, '1000000.00', 'EUR', 'MANUAL', 'UNRECONCILED', 1, CAST(? AS date) + INTERVAL '12 hours', ?"
            .' FROM generate_series(1, ?) AS day',
            [WorkspaceFixture::OWN_WORKSPACE, $account, $today->format('Y-m-d'), $today->format('Y-m-d'), WorkspaceFixture::OWNER_ID, self::SNAPSHOTS],
        );
        $connection->executeStatement('ANALYZE account_balance_snapshots');
        $connection->executeStatement('ANALYZE transaction_transactions');

        return $account;
    }

    private function movementCount(Connection $connection, string $account): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND account_id = ?', [WorkspaceFixture::OWN_WORKSPACE, $account]);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function signIn(KernelBrowser $client): void
    {
        $client->request('GET', '/api/v1/session');
        $csrf = $client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
        $csrf = $client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    private function timedWrite(KernelBrowser $client, string $account, \DateTimeImmutable $today): float
    {
        $body = json_encode([
            'accountId' => $account,
            'amount' => ['value' => '-1.00', 'assetCode' => 'EUR'],
            'nature' => 'EXPENSE',
            'state' => 'BOOKED',
            'bookedOn' => $today->format('Y-m-d'),
            'valueOn' => null,
            'authorizedOn' => null,
            'rawLabel' => 'PERF GUARD',
            'counterparty' => null,
            'note' => null,
            'paymentMethod' => 'CARD',
            'mcc' => null,
            'maskedCard' => null,
            'bankReference' => null,
            'categoryId' => null,
            'splits' => null,
        ], JSON_THROW_ON_ERROR);
        $start = hrtime(true);
        $client->request('POST', '/api/v1/transactions', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body);
        $milliseconds = (hrtime(true) - $start) / 1e6;
        self::assertResponseStatusCodeSame(201);

        return $milliseconds;
    }
}

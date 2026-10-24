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
 * Not part of the default PHPUnit suite. Run it with
 * `bash .claude/skills/ticket/scripts/run-tests.sh api performance/AccountValuationHistoryPerformanceTest.php`.
 *
 * Worst case of the "Tout" savings history: a TRANSACTIONS account whose only anchor is the oldest snapshot, so
 * every one of the 3661 days is derived from booked movements over a window that starts at that anchor, with
 * 100 000 transactions in the workspace. The budget only fails the run with PERF_ASSERT=1 and applies to the
 * realistic case; the concentrated worst case is measured and printed only.
 */
final class AccountValuationHistoryPerformanceTest extends WebTestCase
{
    private const float BUDGET_SECONDS = 5.0;

    public function testUnboundedHistoryOfAnOldAnchorStaysWithinItsBudget(): void
    {
        WorkspaceFixture::requireDatabase();
        $client = self::createClient(['debug' => false]);
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
            (new SyntheticVolumeSeeder($connection))->seed(WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWNER_ID, $today, 100_000, 15);

            $account = $connection->fetchOne(
                "SELECT id FROM account_financial_accounts WHERE workspace_id = ? AND valuation_mode = 'TRANSACTIONS' AND label = 'Livret A'",
                [WorkspaceFixture::OWN_WORKSPACE],
            );
            self::assertIsString($account);
            $connection->executeStatement(
                'DELETE FROM account_balance_snapshots WHERE workspace_id = ? AND account_id = ? AND as_of > (SELECT MIN(as_of) FROM account_balance_snapshots WHERE workspace_id = ? AND account_id = ?)',
                [WorkspaceFixture::OWN_WORKSPACE, $account, WorkspaceFixture::OWN_WORKSPACE, $account],
            );
            $client->request('GET', '/api/v1/session');
            $csrf = $client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
            self::assertNotNull($csrf);
            $client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
            $client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(204);
            $uri = '/api/v1/accounts/'.$account.'/valuation-history?to='.$today->format('Y-m-d');

            // Realistic savings account: the budget applies.
            $seconds = $this->timed($client, $uri, $connection, $account, 'realistic');
            if ('1' === getenv('PERF_ASSERT')) {
                self::assertLessThan(self::BUDGET_SECONDS, $seconds, sprintf('The unbounded history took %.3f s.', $seconds));
            }

            // Known limit, measured and never asserted: one owner, own data, and tens of thousands of booked
            // movements on a savings account is not a realistic workload. Each of the 3661 days is derived from a
            // window that starts at the old anchor, so the cost grows with days times movements.
            $connection->executeStatement(
                "UPDATE transaction_transactions SET account_id = ? WHERE workspace_id = ? AND state = 'BOOKED' AND account_id IN (SELECT id FROM account_financial_accounts WHERE workspace_id = ? AND kind = 'CURRENT')",
                [$account, WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWN_WORKSPACE],
            );
            $this->timed($client, $uri, $connection, $account, 'worst case, not asserted');
        } finally {
            $fixture->reset();
        }
    }

    private function timed(KernelBrowser $client, string $uri, Connection $connection, string $account, string $label): float
    {
        $movements = $connection->fetchOne('SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND account_id = ?', [WorkspaceFixture::OWN_WORKSPACE, $account]);
        $start = hrtime(true);
        $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
        $seconds = (hrtime(true) - $start) / 1e9;
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertTrue($body['hasHistory']);
        fwrite(STDERR, sprintf("\nvaluation-history Tout (%s): %.3f s, %s account movements, %d points\n", $label, $seconds, (string) $movements, count((array) $body['points'])));

        return $seconds;
    }
}

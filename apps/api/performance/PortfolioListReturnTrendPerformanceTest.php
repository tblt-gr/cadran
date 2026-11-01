<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Demo\Application\SeedDemoData;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Not part of the default PHPUnit suite: it lives outside `tests/`. Run it deliberately with:
 *
 *   bash .claude/skills/ticket/scripts/run-tests.sh api performance/PortfolioListReturnTrendPerformanceTest.php
 *
 * ACC-022: the portfolios list with the 12-month return trend of 50 accounts answers in under two seconds.
 */
final class PortfolioListReturnTrendPerformanceTest extends WebTestCase
{
    private const float BUDGET_SECONDS = 2.0;

    public function testFiftyAccountsAnswerTheListWithTheirReturnTrendInUnderTwoSeconds(): void
    {
        WorkspaceFixture::requireDatabase();
        $client = self::createClient(['debug' => false]);
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
            $seed = self::getContainer()->get(SeedDemoData::class);
            self::assertInstanceOf(SeedDemoData::class, $seed);
            $seed(WorkspaceFixture::OWNER_EMAIL);

            $client->request('GET', '/api/v1/session');
            $csrf = $client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
            self::assertNotNull($csrf);
            $client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
            $client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(204);

            // The list is capped at ListPortfolios::MAX_ACCOUNTS: fill it with accounts opened a year ago, each with a
            // first operation, so every account computes its twelve trailing months.
            $connection->executeStatement(
                "INSERT INTO account_financial_accounts (id, workspace_id, label, asset_code, kind, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at)
                 SELECT ('00000000-0000-7000-8000-' || lpad((200000 + n)::text, 12, '0'))::uuid, :ws, 'Perf ' || n, 'EUR', 'PORTFOLIO', 'PORTFOLIO', 'IMMEDIATE', false, false, (CURRENT_DATE - INTERVAL '14 months')::date, NULL, 1, now(), now()
                 FROM generate_series(1, 50) AS n",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE],
            );
            $connection->executeStatement(
                "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
                 SELECT ('00000000-0000-7000-8006-' || lpad(n::text, 12, '0'))::uuid, :ws, ('00000000-0000-7000-8000-' || lpad((200000 + n)::text, 12, '0'))::uuid, 'DEPOSIT', (CURRENT_DATE - INTERVAL '13 months')::date, 1, 'EUR', 100000, 2, 0, 0, 0, 0, 100000, 2, 'ACTIVE', now(), :owner, 1
                 FROM generate_series(1, 50) AS n",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'owner' => WorkspaceFixture::OWNER_ID],
            );

            // The first call warms the container and the opcode cache; the budget applies to the request itself.
            $client->request('GET', '/api/v1/portfolios', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $start = hrtime(true);
            $client->request('GET', '/api/v1/portfolios', server: ['HTTP_ACCEPT' => 'application/json']);
            $seconds = (hrtime(true) - $start) / 1e9;

            self::assertResponseIsSuccessful();
            $items = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['items'] ?? [];
            self::assertGreaterThanOrEqual(50, count($items));
            self::assertLessThan(self::BUDGET_SECONDS, $seconds, sprintf('The portfolios list took %.3f s.', $seconds));
        } finally {
            $fixture->reset();
        }
    }
}

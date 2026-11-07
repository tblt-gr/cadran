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
 *   bash .claude/skills/ticket/scripts/run-tests.sh api performance/HoldingsPerformanceTest.php
 *
 * PTF-015: the holdings of 50 accounts of 40 lines each are aggregated in under two seconds.
 */
final class HoldingsPerformanceTest extends WebTestCase
{
    private const float BUDGET_SECONDS = 2.0;

    public function testFiftyAccountsOfFortyLinesAreAggregatedInUnderTwoSeconds(): void
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

            $connection->executeStatement(
                "INSERT INTO account_financial_accounts (id, workspace_id, label, asset_code, kind, valuation_mode, liquidity_level, include_in_net_worth, include_in_emergency_fund, opened_on, closed_on, version, created_at, updated_at)
                 SELECT ('00000000-0000-7000-8000-' || lpad((200000 + n)::text, 12, '0'))::uuid, :ws, 'Perf ' || n, 'EUR', 'PORTFOLIO', 'PORTFOLIO', 'IMMEDIATE', false, false, (CURRENT_DATE - INTERVAL '14 months')::date, NULL, 1, now(), now()
                 FROM generate_series(1, 50) AS n",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE],
            );
            $connection->executeStatement(
                "INSERT INTO portfolio_instruments (id, workspace_id, name, normalized_name, isin, ticker, venue_mic, instrument_type, asset_class, region, currency_code, quantity_precision, price_precision, source, reviewed_at, reviewed_by, status, used_at, created_at, created_by, updated_at, version)
                 SELECT ('00000000-0000-7000-8000-' || lpad((300000 + n)::text, 12, '0'))::uuid, :ws, 'Perf titre ' || n, 'perf titre ' || n, NULL, 'P' || n, NULL, 'ETF', NULL, NULL, 'EUR', 6, 4, 'MANUAL', now(), :owner, 'ACTIVE', NULL, now(), :owner, now(), 1
                 FROM generate_series(1, 40) AS n",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'owner' => WorkspaceFixture::OWNER_ID],
            );
            // One retained detailed valuation per account, the same forty instruments on every account.
            $connection->executeStatement(
                "INSERT INTO portfolio_valuations (id, workspace_id, account_id, valued_on, asset_code, cash_value, cash_scale, derived_cash_value, derived_cash_scale, cash_source, securities_value_value, securities_value_scale, total_value_value, total_value_scale, observed_total_value, observed_total_scale, detail, completeness, source, note, supersedes_id, superseded_at, withdrawn, recorded_at, recorded_by, version)
                 SELECT ('00000000-0000-7000-8007-' || lpad(a::text, 12, '0'))::uuid, :ws, ('00000000-0000-7000-8000-' || lpad((200000 + a)::text, 12, '0'))::uuid, CURRENT_DATE - 1, 'EUR', 0, 2, 0, 2, 'DERIVED', 4000, 2, 4000, 2, NULL, NULL, 'POSITIONS', 'COMPLETE', 'MANUAL', NULL, NULL, NULL, false, now(), :owner, 1
                 FROM generate_series(1, 50) AS a",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'owner' => WorkspaceFixture::OWNER_ID],
            );
            $connection->executeStatement(
                "INSERT INTO portfolio_position_snapshots (id, workspace_id, valuation_id, instrument_id, quantity_value, quantity_scale, unit_price_value, unit_price_scale, price_date, price_source, market_value_value, market_value_scale, average_cost_basis_value, average_cost_basis_scale, price_quality)
                 SELECT ('00000000-0000-7000-8008-' || lpad((a * 100 + i)::text, 12, '0'))::uuid, :ws, ('00000000-0000-7000-8007-' || lpad(a::text, 12, '0'))::uuid, ('00000000-0000-7000-8000-' || lpad((300000 + i)::text, 12, '0'))::uuid, 10, 0, 10, 4, CURRENT_DATE - 1, 'MANUAL', 100, 2, 90, 2, 'CURRENT'
                 FROM generate_series(1, 50) AS a, generate_series(1, 40) AS i",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE],
            );

            // The first call warms the container and the opcode cache; the budget applies to the request itself.
            $client->request('GET', '/api/v1/holdings', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
            $start = hrtime(true);
            $client->request('GET', '/api/v1/holdings', server: ['HTTP_ACCEPT' => 'application/json']);
            $seconds = (hrtime(true) - $start) / 1e9;

            self::assertResponseIsSuccessful();
            $holdings = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            // The demo workspace adds its own accounts: the read is capped at 50 accounts and says so.
            self::assertGreaterThanOrEqual(40, count($holdings['items']));
            self::assertTrue($holdings['truncated']);
            self::assertLessThan(self::BUDGET_SECONDS, $seconds, sprintf('The holdings took %.3f s.', $seconds));
        } finally {
            $fixture->reset();
        }
    }
}

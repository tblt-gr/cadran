<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Module\Demo\Application\SeedDemoData;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Not part of the default PHPUnit suite: it lives outside `tests/`. Run it deliberately with:
 *
 *   bash .claude/skills/ticket/scripts/run-tests.sh api performance/PortfolioAnnualPerformanceTest.php
 *
 * PTF-003 acceptance criterion: the annual performance of the demo account (twelve months, the year and the whole life)
 * answers in under one second.
 *
 * PTF-018: an account of 9 000 active operations answers it in under three seconds.
 */
final class PortfolioAnnualPerformanceTest extends WebTestCase
{
    private const float BUDGET_SECONDS = 1.0;
    private const float DENSE_BUDGET_SECONDS = 3.0;

    public function testAnAccountOfNineThousandOperationsAnswersTheAnnualPerformanceInUnderThreeSeconds(): void
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
            $portfolio = new PortfolioFixture($connection);
            $portfolio->account(openedOn: '2025-01-01');
            $portfolio->instrument();
            // Deposits and one-share buys alternate over the 360 first days of 2025.
            $connection->executeStatement(
                "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, instrument_id, asset_code, quantity_value, quantity_scale, unit_price_value, unit_price_scale, gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
                 SELECT ('00000000-0000-7000-8007-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, CASE WHEN n % 2 = 1 THEN 'DEPOSIT' ELSE 'BUY' END, DATE '2025-01-01' + ((n - 1) * 360 / 9000), n,
                   CASE WHEN n % 2 = 1 THEN NULL ELSE CAST(:instrument AS uuid) END, 'EUR', CASE WHEN n % 2 = 1 THEN NULL ELSE 1 END, CASE WHEN n % 2 = 1 THEN NULL ELSE 0 END,
                   CASE WHEN n % 2 = 1 THEN NULL ELSE 100 END, CASE WHEN n % 2 = 1 THEN NULL ELSE 2 END, 100, 2, 0, 0, 0, 0, CASE WHEN n % 2 = 1 THEN 100 ELSE -100 END, 2, 'ACTIVE', now(), :owner, 1
                 FROM generate_series(1, 9000) AS n",
                ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => PortfolioFixture::ACCOUNT, 'instrument' => PortfolioFixture::ETF, 'owner' => WorkspaceFixture::OWNER_ID],
            );

            $client->request('GET', '/api/v1/session');
            $csrf = $client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
            self::assertNotNull($csrf);
            $client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
            $client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(204);

            for ($month = 1; $month <= 12; ++$month) {
                $day = (new \DateTimeImmutable(sprintf('2025-%02d-01', $month)))->format('Y-m-t');
                $client->request('POST', '/api/v1/accounts/'.PortfolioFixture::ACCOUNT.'/portfolio-valuations', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['valuedOn' => $day, 'detail' => 'POSITIONS', 'prices' => [['instrumentId' => PortfolioFixture::ETF, 'price' => '101.00', 'priceDate' => $day]]], JSON_THROW_ON_ERROR));
                self::assertResponseStatusCodeSame(201);
            }

            $uri = '/api/v1/accounts/'.PortfolioFixture::ACCOUNT.'/portfolio-performance/annual?year=2025';
            // The first call warms the container and the opcode cache; the budget applies to the request itself.
            $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $start = hrtime(true);
            $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
            $seconds = (hrtime(true) - $start) / 1e9;

            self::assertResponseIsSuccessful();
            fwrite(STDERR, sprintf("Annual performance, 9 000 operations: %.3f s\n", $seconds));
            self::assertLessThan(self::DENSE_BUDGET_SECONDS, $seconds, sprintf('The annual performance took %.3f s.', $seconds));
        } finally {
            $fixture->reset();
        }
    }

    public function testTheDemoAccountAnswersTheAnnualPerformanceInUnderOneSecond(): void
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

            $account = $connection->fetchOne("SELECT id FROM account_financial_accounts WHERE workspace_id = ? AND valuation_mode = 'PORTFOLIO' ORDER BY label LIMIT 1", [WorkspaceFixture::OWN_WORKSPACE]);
            $year = $connection->fetchOne("SELECT to_char(MAX(valued_on), 'YYYY') FROM portfolio_valuations WHERE workspace_id = ? AND account_id = ? AND superseded_at IS NULL", [WorkspaceFixture::OWN_WORKSPACE, $account]);
            $uri = '/api/v1/accounts/'.$account.'/portfolio-performance/annual?year='.$year;

            // The first call warms the container and the opcode cache; the budget applies to the request itself.
            $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $start = hrtime(true);
            $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
            $seconds = (hrtime(true) - $start) / 1e9;

            self::assertResponseIsSuccessful();
            self::assertLessThan(self::BUDGET_SECONDS, $seconds, sprintf('The annual performance took %.3f s.', $seconds));
        } finally {
            $fixture->reset();
        }
    }
}

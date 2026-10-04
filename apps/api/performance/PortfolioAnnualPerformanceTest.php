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
 *   bash .claude/skills/ticket/scripts/run-tests.sh api performance/PortfolioAnnualPerformanceTest.php
 *
 * PTF-003 acceptance criterion: the annual performance of the demo account (twelve months, the year and the whole life)
 * answers in under one second.
 */
final class PortfolioAnnualPerformanceTest extends WebTestCase
{
    private const float BUDGET_SECONDS = 1.0;

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

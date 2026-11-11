<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\Infrastructure\Persistence\DbalListRevisionRepository;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListRevisionTest extends WebTestCase
{
    private KernelBrowser $client;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->instrument();
        $portfolio->instrument(PortfolioFixture::STOCK, WorkspaceFixture::OTHER_WORKSPACE);
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->jsonRequest('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        foreach (['/instruments', '/products', '/product-models', '/categorization-rules', '/accounts/'.PortfolioFixture::ACCOUNT.'/portfolio-operations', '/accounts/'.PortfolioFixture::ACCOUNT.'/portfolio-valuations'] as $path) {
            yield $path => ['/api/v1'.$path];
        }
    }

    #[DataProvider('paths')]
    public function testRevisionIsOptInAndStableForAnUnchangedList(string $path): void
    {
        $this->client->request('GET', $path.'?page=1&pageSize=1');
        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('X-List-Revision');
        $this->client->request('GET', $path.'?page=1&pageSize=1', server: ['HTTP_X_LIST_REVISION' => 'start']);
        self::assertResponseIsSuccessful();
        $revision = $this->client->getResponse()->headers->get('X-List-Revision');
        self::assertNotNull($revision);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revision);
        $this->client->request('GET', $path.'?page=2&pageSize=1', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-List-Revision', $revision);
    }

    public function testUpdatesAndDeletionsRejectTheOldRevisionButForeignWritesDoNot(): void
    {
        $this->client->request('GET', '/api/v1/instruments?pageSize=1', server: ['HTTP_X_LIST_REVISION' => 'start']);
        $revision = $this->client->getResponse()->headers->get('X-List-Revision');
        self::assertNotNull($revision);
        $this->connection()->executeStatement('UPDATE portfolio_instruments SET name = :name WHERE workspace_id = :workspace_id AND id = :id', ['name' => 'Foreign edit', 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'id' => PortfolioFixture::STOCK]);
        $this->client->request('GET', '/api/v1/instruments?page=2&pageSize=1', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseIsSuccessful();
        $this->connection()->executeStatement('UPDATE portfolio_instruments SET name = :name WHERE workspace_id = :workspace_id AND id = :id', ['name' => 'Own edit', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => PortfolioFixture::ETF]);
        $this->client->request('GET', '/api/v1/instruments?page=2&pageSize=1', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonStringEqualsJsonString(json_encode(['type' => '/problems/lists.revision_stale', 'title' => 'Liste modifiée entre-temps', 'status' => 409, 'detail' => 'Les données ou les filtres ont changé. Rechargez la liste depuis le début.'], JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/instruments?pageSize=1', server: ['HTTP_X_LIST_REVISION' => 'start']);
        $revision = $this->client->getResponse()->headers->get('X-List-Revision');
        self::assertNotNull($revision);
        $this->connection()->delete('portfolio_instruments', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => PortfolioFixture::ETF]);
        $this->client->request('GET', '/api/v1/instruments?page=2&pageSize=1', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseStatusCodeSame(409);
    }

    #[DataProvider('paths')]
    public function testEachListRejectsARevisionInvalidatedByACommittedWrite(string $path): void
    {
        $this->client->request('GET', $path.'?pageSize=1', server: ['HTTP_X_LIST_REVISION' => 'start']);
        $revision = $this->client->getResponse()->headers->get('X-List-Revision');
        self::assertNotNull($revision);
        if ('/api/v1/products' === $path) {
            $this->connection()->executeStatement('UPDATE catalog_products SET display_name = display_name WHERE code = :code', ['code' => 'LIVRET_A']);
        } else {
            (new PortfolioFixture($this->connection()))->instrument('00000000-0000-7000-8000-000000000b03', name: 'New instrument');
        }
        $this->client->request('GET', $path.'?page=2&pageSize=1', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testARolledBackWriteDoesNotInvalidateTheRevision(): void
    {
        $this->client->request('GET', '/api/v1/instruments?pageSize=1', server: ['HTTP_X_LIST_REVISION' => 'start']);
        $revision = $this->client->getResponse()->headers->get('X-List-Revision');
        self::assertNotNull($revision);
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->delete('portfolio_instruments', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => PortfolioFixture::ETF]);
        $connection->rollBack();
        $this->client->request('GET', '/api/v1/instruments?page=2&pageSize=1', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-List-Revision', $revision);
    }

    public function testQueryChangesCannotReuseARevision(): void
    {
        $this->client->request('GET', '/api/v1/instruments', server: ['HTTP_X_LIST_REVISION' => 'start']);
        $revision = $this->client->getResponse()->headers->get('X-List-Revision');
        self::assertNotNull($revision);
        $this->client->request('GET', '/api/v1/instruments?status=all', server: ['HTTP_X_LIST_REVISION' => $revision]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAConcurrentWriteCannotCommitWhileAListPageIsBeingRead(): void
    {
        $connection = $this->connection();
        $otherConnection = DriverManager::getConnection($connection->getParams());
        $otherConnection->executeStatement("SET lock_timeout = '100ms'");
        try {
            $connection->transactional(function () use ($connection, $otherConnection): void {
                (new DbalListRevisionRepository($connection))->lock(WorkspaceFixture::own(), false);
                try {
                    $otherConnection->executeStatement('UPDATE portfolio_instruments SET name = :name WHERE workspace_id = :workspace_id AND id = :id', ['name' => 'Concurrent edit', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => PortfolioFixture::ETF]);
                    self::fail('The concurrent write committed inside a guarded page read.');
                } catch (DriverException $exception) {
                    self::assertSame('55P03', $exception->getSQLState());
                    self::assertSame('ETF Monde', $connection->fetchOne('SELECT name FROM portfolio_instruments WHERE workspace_id = :workspace_id AND id = :id', ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => PortfolioFixture::ETF]));
                }
            });
            self::assertSame(1, $otherConnection->executeStatement('UPDATE portfolio_instruments SET name = :name WHERE workspace_id = :workspace_id AND id = :id', ['name' => 'Concurrent edit', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => PortfolioFixture::ETF]));
        } finally {
            $otherConnection->close();
        }
    }

    public function testInvalidHeadersAndRestartingFromALaterPageAreRejected(): void
    {
        $this->client->request('GET', '/api/v1/instruments', server: ['HTTP_X_LIST_REVISION' => str_repeat('a', 65)]);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/v1/instruments?page=2', server: ['HTTP_X_LIST_REVISION' => 'start']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testForeignAccountRemainsHiddenWithARevision(): void
    {
        $this->client->request('GET', '/api/v1/accounts/00000000-0000-7000-8000-000000000fff/portfolio-operations', server: ['HTTP_X_LIST_REVISION' => 'start']);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseNotHasHeader('X-List-Revision');
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}

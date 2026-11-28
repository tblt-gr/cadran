<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Infrastructure\Persistence\DbalGoalRepository;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Infrastructure\Persistence\DbalCategoryRepository;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransferRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransferRepository;
use App\Tests\Support\LogCapture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A repository or use case reporting an internal inconsistency, such as a row hydrated outside its
 * requested workspace, is a server fault: a logged 500, never an invalid request.
 */
final class InternalInconsistencyTest extends WebTestCase
{
    private const string FAULT = 'Zx9-row escaped its requested workspace';
    private const string UNKNOWN_ID = '00000000-0000-7000-8000-00000000ffff';
    private const string GROUP = '00000000-0000-7000-8000-0000000000c1';

    private KernelBrowser $client;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $connection->insert('account_groups', [
            'id' => self::GROUP, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Épargne', 'parent_id' => null,
            'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
        ]);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testACategoryUpdateReadingAForeignRowIsAServerError(): void
    {
        $categories = self::createStub(CategoryRepository::class);
        $categories->method('findForUpdate')->willThrowException(new \UnexpectedValueException(self::FAULT));
        $logs = $this->replace(DbalCategoryRepository::class, $categories);

        $this->send('PUT', '/api/v1/categories/'.self::UNKNOWN_ID, [
            'type' => 'EXPENSE', 'label' => 'Courses', 'icon' => null, 'color' => null,
            'defaultAnalyticAxes' => [], 'budgetIncluded' => true, 'sortOrder' => 0, 'version' => 1,
        ]);

        $this->assertLoggedServerError($logs);
    }

    public function testATransactionUpdateReadingAForeignRowIsAServerError(): void
    {
        $transfers = self::createStub(TransferRepository::class);
        $transfers->method('findByLegTransactionId')->willThrowException(new \UnexpectedValueException(self::FAULT));
        $logs = $this->replace(DbalTransferRepository::class, $transfers);

        $this->send('PUT', '/api/v1/transactions/'.self::UNKNOWN_ID, [
            'accountId' => self::UNKNOWN_ID, 'amount' => ['value' => '-12.00', 'assetCode' => 'EUR'], 'nature' => 'EXPENSE',
            'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null, 'authorizedOn' => null, 'rawLabel' => 'Courses',
            'counterparty' => null, 'note' => null, 'paymentMethod' => null, 'mcc' => null, 'maskedCard' => null,
            'bankReference' => null, 'categoryId' => null, 'splits' => null, 'version' => 1, 'userLabel' => null,
        ]);

        $this->assertLoggedServerError($logs);
    }

    public function testATransferVoidWithAMissingLegIsAServerError(): void
    {
        $source = $this->createAccount('Courant');
        $target = $this->createAccount('Livret');
        $this->send('POST', '/api/v1/transfers', [
            'sourceAccountId' => $source, 'targetAccountId' => $target,
            'sourceAmount' => ['value' => '10.00', 'assetCode' => 'EUR'], 'targetAmount' => null,
            'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null, 'label' => 'Virement', 'note' => null, 'fee' => null,
        ]);
        self::assertResponseStatusCodeSame(201);
        $transfer = $this->decode();
        $transactions = self::createStub(TransactionRepository::class);
        $transactions->method('findForUpdate')->willReturn(null);
        $logs = $this->replace(DbalTransactionRepository::class, $transactions);

        $id = $transfer['id'] ?? null;
        self::assertIsString($id);
        $this->send('POST', '/api/v1/transfers/'.$id.'/void', ['version' => $transfer['version']]);

        $this->assertLoggedServerError($logs);
    }

    public function testAGoalUpdateReadingAForeignRowIsAServerError(): void
    {
        $goals = self::createStub(GoalRepository::class);
        $goals->method('findForUpdate')->willThrowException(new \UnexpectedValueException(self::FAULT));
        $logs = $this->replace(DbalGoalRepository::class, $goals);

        $this->send('PUT', '/api/v1/goals/'.self::UNKNOWN_ID, ['label' => 'Vacances', 'version' => 1]);

        $this->assertLoggedServerError($logs);
    }

    /** Boots a fresh kernel, so the replaced service has not been built yet. */
    private function replace(string $id, object $service): LogCapture
    {
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set($id, $service);

        return LogCapture::attach(self::getContainer()->get('logger'));
    }

    private function assertLoggedServerError(LogCapture $logs): void
    {
        self::assertResponseStatusCodeSame(500);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        $requestId = $this->client->getResponse()->headers->get('X-Request-Id');
        self::assertNotNull($requestId);
        $matching = array_filter($logs->records(), static fn (array $record): bool => is_array($record['data']['extra'] ?? null)
            && ($record['data']['extra']['request_id'] ?? null) === $requestId
            && str_contains($record['line'], 'UnexpectedValueException'));
        self::assertNotEmpty($matching, 'The fault must be logged with its class and the request id of its response.');
        self::assertFalse($logs->contains(self::FAULT), 'The exception message must stay out of the logs.');
    }

    private function createAccount(string $label): string
    {
        $this->send('POST', '/api/v1/accounts', [
            'label' => $label, 'assetCode' => 'EUR', 'kind' => 'SAVINGS', 'productCode' => null, 'productModelId' => null,
            'institution' => null, 'maskedIdentifier' => null, 'valuationMode' => 'TRANSACTIONS', 'liquidityLevel' => 'IMMEDIATE',
            'includeInNetWorth' => true, 'includeInEmergencyFund' => false, 'openedOn' => '2026-01-10', 'closedOn' => null,
            'primaryGroupId' => self::GROUP,
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->decode()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

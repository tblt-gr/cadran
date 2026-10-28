<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\LogCapture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ASVS 5.0 V16 (security logging): no amount, label, e-mail or password submitted through the API may reach
 * a log record, in the message or in the context, with the logger forced to debug so that even records the
 * deployed logger drops are inspected (the one exception is documented on assertNothingSensitiveWasLoggedAtTheDeployedLevel). Every value below is a distinctive sentinel
 * so a match cannot be a coincidence; the amounts are searched in canonical, raw and digit-prefix form.
 */
final class SensitiveLoggingTest extends WebTestCase
{
    private const string GROUP = '00000000-0000-7000-8000-0000000000c1';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c2';
    private const string WRONG_PASSWORD = 'Zx9-wrong-passphrase-sentinel';
    private const string UNKNOWN_EMAIL = 'zx9-ghost-sentinel@example.test';
    private const string ACCOUNT_LABEL = 'Zx9-sentinel-account-label';
    private const string TRANSACTION_LABEL = 'Zx9-sentinel-transaction-label';
    private const string COUNTERPARTY = 'Zx9-sentinel-counterparty';
    private const string NOTE = 'Zx9-sentinel-note';
    private const string TRANSFER_LABEL = 'Zx9-sentinel-transfer-label';
    private const string DATABASE_LABEL = 'Zx9-sentinel-database-label';
    private const string REFUSED_LABEL = 'Zx9-sentinel-refused-label';
    private const string EXPENSE = '987654.32';
    private const string TRANSFER = '246813.57';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private LogCapture $logger;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->connection->insert('account_groups', [
            'id' => self::GROUP, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Épargne', 'parent_id' => null,
            'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00',
        ]);
        $this->connection->insert('category_categories', [
            'id' => self::CATEGORY, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE', 'label' => 'Courses',
            'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true,
            'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ]);

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->logger = LogCapture::attachEveryRecord(self::getContainer()->get('logger'));
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheCaptureSeesTheMessageAndTheContextOfEveryLevel(): void
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);

        $logger->debug('Zx9-control-message');
        $logger->info('Zx9 control context', ['probe' => 'Zx9-control-context', 'nested' => ['deep' => 'Zx9-control-nested']]);
        $logger->error('Zx9 control exception', ['exception' => new \RuntimeException('Zx9-control-exception')]);

        self::assertTrue($this->logger->contains('Zx9-control-message'));
        self::assertTrue($this->logger->contains('Zx9-control-context'));
        self::assertTrue($this->logger->contains('Zx9-control-nested'));
        self::assertTrue($this->logger->contains('Zx9-control-exception'));
    }

    public function testASuccessfulAndAFailedLoginNeverLogTheEmailOrThePassword(): void
    {
        $this->login(WorkspaceFixture::OWNER_EMAIL, self::WRONG_PASSWORD);
        self::assertResponseStatusCodeSame(401);
        $this->login(self::UNKNOWN_EMAIL, self::WRONG_PASSWORD);
        self::assertResponseStatusCodeSame(401);
        $this->login(WorkspaceFixture::OWNER_EMAIL, WorkspaceFixture::OWNER_PASSWORD);
        self::assertResponseStatusCodeSame(204);

        $this->assertNothingSensitiveWasLogged([
            WorkspaceFixture::OWNER_PASSWORD,
            self::UNKNOWN_EMAIL,
            self::WRONG_PASSWORD,
        ]);
        $this->assertNothingSensitiveWasLoggedAtTheDeployedLevel([WorkspaceFixture::OWNER_EMAIL]);
    }

    public function testCreatingAnAccountAndATransactionNeverLogsTheirLabelsOrAmounts(): void
    {
        $this->signIn();
        $account = $this->createAccount(self::ACCOUNT_LABEL);
        $this->createTransaction(self::stringValue($account, 'id'));

        $this->assertNothingSensitiveWasLogged([
            self::ACCOUNT_LABEL, self::TRANSACTION_LABEL, self::COUNTERPARTY, self::NOTE, self::EXPENSE, '987654',
        ]);
    }

    public function testATransferNeverLogsItsLabelOrItsAmount(): void
    {
        $this->signIn();
        $source = self::stringValue($this->createAccount(self::ACCOUNT_LABEL), 'id');
        $target = self::stringValue($this->createAccount(self::ACCOUNT_LABEL.'-target'), 'id');

        $this->send('POST', '/api/v1/transfers', [
            'sourceAccountId' => $source,
            'targetAccountId' => $target,
            'sourceAmount' => ['value' => self::TRANSFER, 'assetCode' => 'EUR'],
            'targetAmount' => ['value' => self::TRANSFER, 'assetCode' => 'EUR'],
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-14',
            'valueOn' => null,
            'label' => self::TRANSFER_LABEL,
            'note' => self::NOTE,
            'fee' => null,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->assertNothingSensitiveWasLogged([self::TRANSFER_LABEL, self::NOTE, self::TRANSFER, '246813']);
    }

    public function testReadingReportsNeverLogsTheLedgerTheyAreBuiltFrom(): void
    {
        $this->signIn();
        $account = $this->createAccount(self::ACCOUNT_LABEL);
        $this->createTransaction(self::stringValue($account, 'id'));

        $this->send('GET', '/api/v1/reports/monthly?month=2026-03');
        self::assertResponseIsSuccessful();
        $this->send('GET', '/api/v1/net-worth');
        self::assertResponseIsSuccessful();

        $this->assertNothingSensitiveWasLogged([self::ACCOUNT_LABEL, self::TRANSACTION_LABEL, self::COUNTERPARTY, self::EXPENSE, '987654']);
    }

    public function testRefusedRequestsNeverLogTheirPayload(): void
    {
        $this->signIn();
        $accountId = self::stringValue($this->createAccount(self::ACCOUNT_LABEL), 'id');

        $key = 'zx9-sentinel-idempotency-key-0001';
        $this->createTransaction($accountId, $key);
        $this->createTransaction($accountId, $key, label: self::REFUSED_LABEL);
        self::assertResponseStatusCodeSame(409);

        // 12 decimals on a 2-decimal asset: refused as a validation problem, label and amount in the payload.
        $this->send('POST', '/api/v1/transactions', $this->transactionBody($accountId, label: self::REFUSED_LABEL, amount: '-987654.329999999999'));
        self::assertResponseStatusCodeSame(422);

        $this->send('GET', '/api/v1/transactions/00000000-0000-7000-8000-00000000ffff');
        self::assertResponseStatusCodeSame(404);

        $this->assertNothingSensitiveWasLogged([self::REFUSED_LABEL, self::TRANSACTION_LABEL, self::EXPENSE, '987654']);
    }

    public function testAnUnhandledDatabaseErrorLogsItsClassAndNeverTheFailingRow(): void
    {
        $this->signIn();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::REQUEST, function (RequestEvent $event): void {
            if ('/api/v1/status' !== $event->getRequest()->getPathInfo()) {
                return;
            }
            // PostgreSQL reports the whole refused row in the error detail: "Failing row contains (...)".
            $this->connection->executeStatement(
                'INSERT INTO account_financial_accounts (id, workspace_id, label, asset_code) VALUES (?, ?, ?, NULL)',
                ['00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::OWN_WORKSPACE, self::DATABASE_LABEL],
            );
        }, 1000);

        $this->send('GET', '/api/v1/status');

        self::assertResponseStatusCodeSame(500);
        self::assertTrue($this->logger->contains(NotNullConstraintViolationException::class), 'The exception class must stay in the logs.');
        self::assertTrue($this->logger->contains('23502'), 'The SQLSTATE must stay in the logs.');
        $this->assertNothingSensitiveWasLogged([self::DATABASE_LABEL, 'Failing row contains', 'asset_code']);
    }

    /** @param list<string> $secrets */
    private function assertNothingSensitiveWasLogged(array $secrets): void
    {
        foreach ($secrets as $secret) {
            self::assertFalse($this->logger->contains($secret), 'A submitted value reached the logs: '.$secret);
        }
    }

    /**
     * The owner e-mail is the security user identifier, so the framework's own info-level trace of a
     * successful authentication ("Authenticator successful!", token in its context) carries it. The runtime
     * image sets no SHELL_VERBOSITY, which leaves the framework logger at error level, and its default
     * formatter prints a message without the context. The e-mail is therefore asserted absent from what
     * the deployed logger can emit (warning and above, stricter than error), not from the debug trace.
     *
     * @param list<string> $secrets
     */
    private function assertNothingSensitiveWasLoggedAtTheDeployedLevel(array $secrets): void
    {
        foreach ($secrets as $secret) {
            self::assertFalse($this->logger->contains($secret, 'warning'), 'A submitted value reached the deployed logs: '.$secret);
        }
    }

    private function login(string $email, string $password): void
    {
        $this->send('POST', '/api/v1/session', ['email' => $email, 'password' => $password]);
    }

    private function signIn(): void
    {
        $this->login(WorkspaceFixture::OWNER_EMAIL, WorkspaceFixture::OWNER_PASSWORD);
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function createAccount(string $label): array
    {
        $this->send('POST', '/api/v1/accounts', [
            'label' => $label, 'assetCode' => 'EUR', 'kind' => 'SAVINGS', 'productCode' => null, 'productModelId' => null,
            'institution' => null, 'maskedIdentifier' => null, 'valuationMode' => 'TRANSACTIONS', 'liquidityLevel' => 'IMMEDIATE',
            'includeInNetWorth' => true, 'includeInEmergencyFund' => false, 'openedOn' => '2026-01-10', 'closedOn' => null,
            'primaryGroupId' => self::GROUP,
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    private function createTransaction(string $accountId, ?string $idempotencyKey = null, string $label = self::TRANSACTION_LABEL): void
    {
        $this->send('POST', '/api/v1/transactions', $this->transactionBody($accountId, $label), $idempotencyKey);
        if (self::TRANSACTION_LABEL === $label) {
            self::assertResponseStatusCodeSame(201);
        }
    }

    /** @return array<string, mixed> */
    private function transactionBody(string $accountId, string $label = self::TRANSACTION_LABEL, string $amount = '-'.self::EXPENSE): array
    {
        return [
            'accountId' => $accountId, 'amount' => ['value' => $amount, 'assetCode' => 'EUR'], 'nature' => 'EXPENSE',
            'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null, 'authorizedOn' => null, 'rawLabel' => $label,
            'counterparty' => self::COUNTERPARTY, 'note' => self::NOTE, 'paymentMethod' => 'CARD', 'mcc' => null, 'maskedCard' => null,
            'bankReference' => null, 'categoryId' => self::CATEGORY, 'splits' => null,
        ];
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $idempotencyKey) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        $this->client->request($method, $path, server: $server, content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $typed = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    /** @param array<string, mixed> $data */
    private static function stringValue(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsString($data[$key]);

        return $data[$key];
    }
}

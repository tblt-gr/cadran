<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A transfer into or out of a securities portfolio moves its cash and its valuation without a manual step. */
final class PortfolioTransferSyncTest extends WebTestCase
{
    private const string PORTFOLIO = PortfolioFixture::ACCOUNT;
    private const string CURRENT = '00000000-0000-7000-8000-0000000000d3';
    private const string OTHER_CURRENT = '00000000-0000-7000-8000-0000000000d4';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
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

        $portfolio = new PortfolioFixture($connection);
        $portfolio->account();
        $portfolio->account(self::CURRENT, label: 'Compte courant', kind: 'CURRENT', valuationMode: 'TRANSACTIONS');
        $portfolio->account(self::OTHER_CURRENT, label: 'Livret', kind: 'CURRENT', valuationMode: 'TRANSACTIONS');

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

    public function testATransferIntoAPortfolioRecordsTheLinkedDepositAndMovesTheValuation(): void
    {
        $this->recordTotal('2026-03-05', '1000.00');

        $transfer = $this->createTransfer(self::CURRENT, self::PORTFOLIO, '500.00', '2026-03-10');

        $operation = $this->activeOperations()[0] ?? null;
        self::assertNotNull($operation);
        self::assertSame('DEPOSIT', $operation['operation_type']);
        self::assertSame('2026-03-10', $operation['trade_date']);
        self::assertSame($this->legOn($transfer, self::PORTFOLIO), $operation['linked_transaction_id']);
        self::assertSame('500', $this->cashBalance());
        self::assertSame('1500', $this->latestTotal());
        self::assertSame([], $this->cashWarnings());
    }

    public function testNoValuationIsInventedWhenThePortfolioWasNeverValued(): void
    {
        $this->createTransfer(self::CURRENT, self::PORTFOLIO, '500.00', '2026-03-10');

        self::assertCount(1, $this->activeOperations());
        self::assertSame('500', $this->cashBalance());
        self::assertNull($this->latestTotal());
    }

    public function testATransferOutOfAPortfolioRecordsAWithdrawal(): void
    {
        $this->recordTotal('2026-03-05', '1000.00');
        $this->createTransfer(self::CURRENT, self::PORTFOLIO, '500.00', '2026-03-10');

        $this->createTransfer(self::PORTFOLIO, self::OTHER_CURRENT, '200.00', '2026-03-12');

        self::assertSame(['DEPOSIT', 'WITHDRAWAL'], array_column($this->activeOperations(), 'operation_type'));
        self::assertSame('300', $this->cashBalance());
        self::assertSame('1300', $this->latestTotal());
        self::assertSame([], $this->cashWarnings());
    }

    public function testMovingTheTransferDateMovesTheLinkedOperation(): void
    {
        $transfer = $this->createTransfer(self::CURRENT, self::PORTFOLIO, '500.00', '2026-03-10');

        $this->send('PUT', '/api/v1/transfers/'.$this->string($transfer['id']), [
            'sourceAccountId' => self::CURRENT, 'targetAccountId' => self::PORTFOLIO, 'state' => 'BOOKED',
            'bookedOn' => '2026-03-12', 'valueOn' => null, 'label' => 'Virement PEA', 'note' => null, 'fee' => null,
            'version' => $transfer['version'],
        ]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $operations = $this->activeOperations();
        self::assertCount(1, $operations);
        self::assertSame('2026-03-12', $operations[0]['trade_date']);
        self::assertSame([], $this->cashWarnings());
    }

    public function testVoidingTheTransferVoidsTheOperationAndRestoresTheValuation(): void
    {
        $this->recordTotal('2026-03-05', '1000.00');
        $transfer = $this->createTransfer(self::CURRENT, self::PORTFOLIO, '500.00', '2026-03-10');

        $this->send('POST', '/api/v1/transfers/'.$this->string($transfer['id']).'/void', ['version' => $transfer['version']]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->activeOperations());
        self::assertSame('0', $this->cashBalance());
        self::assertSame('1000', $this->latestTotal());
    }

    public function testATransferBetweenCurrentAccountsTouchesNoPortfolio(): void
    {
        $this->createTransfer(self::CURRENT, self::OTHER_CURRENT, '500.00', '2026-03-10');

        self::assertSame([], $this->activeOperations());
    }

    /** @return array<string, mixed> */
    private function createTransfer(string $from, string $to, string $amount, string $bookedOn): array
    {
        $this->send('POST', '/api/v1/transfers', [
            'sourceAccountId' => $from, 'targetAccountId' => $to,
            'sourceAmount' => ['value' => $amount, 'assetCode' => 'EUR'], 'targetAmount' => ['value' => $amount, 'assetCode' => 'EUR'],
            'state' => 'BOOKED', 'bookedOn' => $bookedOn, 'valueOn' => null, 'label' => 'Virement PEA', 'note' => null, 'fee' => null,
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    private function recordTotal(string $valuedOn, string $total): void
    {
        $this->send('POST', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-valuations', [
            'valuedOn' => $valuedOn, 'detail' => 'TOTAL', 'totalValue' => ['value' => $total, 'assetCode' => 'EUR'],
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return list<array<string, mixed>> */
    private function activeOperations(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT operation_type, trade_date::text AS trade_date, linked_transaction_id FROM portfolio_operations WHERE account_id = :account AND state = 'ACTIVE' ORDER BY trade_date, sequence",
            ['account' => self::PORTFOLIO],
        );
    }

    private function latestTotal(): ?string
    {
        $total = $this->connection->fetchOne(
            'SELECT total_value_value FROM portfolio_valuations WHERE account_id = :account AND superseded_at IS NULL AND NOT withdrawn ORDER BY valued_on DESC, recorded_at DESC LIMIT 1',
            ['account' => self::PORTFOLIO],
        );

        if (false === $total) {
            return null;
        }
        self::assertIsString($total);

        return self::trim($total);
    }

    private function cashBalance(): string
    {
        $this->client->request('GET', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-cash?from=2026-01-01&to=2026-11-01', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $cash = $this->decode();
        $balance = $cash['currentBalance'];
        self::assertIsArray($balance);

        return self::trim($this->string($balance['value']));
    }

    /** @return list<mixed> */
    private function cashWarnings(): array
    {
        $this->client->request('GET', '/api/v1/accounts/'.self::PORTFOLIO.'/portfolio-cash?from=2026-01-01&to=2026-11-01', server: ['HTTP_ACCEPT' => 'application/json']);
        $cash = $this->decode();
        $warnings = $cash['warnings'];
        self::assertIsArray($warnings);

        return array_values($warnings);
    }

    /** @param array<string, mixed> $transfer */
    private function legOn(array $transfer, string $accountId): string
    {
        foreach (['source', 'target'] as $side) {
            $leg = $transfer[$side];
            if (is_array($leg) && ($leg['accountId'] ?? null) === $accountId) {
                return $this->string($leg['id']);
            }
        }
        self::fail('The transfer has no leg on this account.');
    }

    /** Drops the stored trailing zeros: '1500.000000' and '1500' are the same figure. */
    private static function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $typed = [];
        foreach ($decoded as $key => $item) {
            self::assertIsString($key);
            $typed[$key] = $item;
        }

        return $typed;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * @phpstan-type InsuranceInput array{type: string, tradeDate: string, grossAmount: array{value: string, assetCode: string}, legs: list<array<string, mixed>>, fees?: array{value: string, assetCode: string}, withheldIncomeTax?: array{value: string, assetCode: string}, withheldSocialContributions?: array{value: string, assetCode: string}}
 */
abstract class LifeInsuranceApiTestCase extends WebTestCase
{
    use ClosesPeriods {
        closeMonthInDatabase as protected;
    }

    protected const string LIFE = PortfolioFixture::ACCOUNT;
    protected const string SECURITIES = PortfolioFixture::CASH_ACCOUNT;
    protected const string FUND = '00000000-0000-7000-8000-000000000b03';
    protected const string ETF = PortfolioFixture::ETF;

    protected KernelBrowser $client;
    protected Connection $connection;
    private WorkspaceFixture $fixture;
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
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-10-05T12:00:00+00:00'));

        $portfolio = new PortfolioFixture($connection);
        $portfolio->account(self::LIFE, label: 'Assurance-vie', kind: 'INSURANCE_CONTRACT');
        $portfolio->account(self::SECURITIES, label: 'Compte-titres');
        $portfolio->euroFund(self::FUND);
        $portfolio->instrument(self::ETF, name: 'ETF Monde', isin: 'IE00B4L5Y983');

        $this->configureServices();
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
        $this->createContract();
    }

    protected function configureServices(): void
    {
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $input */
    protected function refused(array $input, string $code): void
    {
        $this->send('POST', $this->operations(self::LIFE), $input);
        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, $this->decode()['code']);
        self::assertStringNotContainsString('api.problem.', self::string($this->decode()['detail']));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function record(array $input): array
    {
        $this->send('POST', $this->operations(self::LIFE), $input);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @return InsuranceInput */
    protected function premium(): array
    {
        return ['type' => 'PREMIUM', 'tradeDate' => '2026-01-15', 'grossAmount' => $this->eur('10000.00'), 'fees' => $this->eur('100.00'), 'legs' => [
            ['direction' => 'IN', 'instrumentId' => self::FUND, 'amount' => $this->eur('6000.00')],
            ['direction' => 'IN', 'instrumentId' => self::ETF, 'quantity' => '40', 'unitPrice' => '97.50', 'amount' => $this->eur('3900.00')],
        ]];
    }

    /** @return InsuranceInput */
    protected function surrender(): array
    {
        return ['type' => 'PARTIAL_SURRENDER', 'tradeDate' => '2026-06-10', 'grossAmount' => $this->eur('1050.00'),
            'withheldIncomeTax' => $this->eur('7.50'), 'withheldSocialContributions' => $this->eur('8.60'),
            'legs' => [['direction' => 'OUT', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '105.00', 'amount' => $this->eur('1050.00')]]];
    }

    /** @return InsuranceInput */
    protected function full(): array
    {
        return ['type' => 'FULL_SURRENDER', 'tradeDate' => '2026-06-10', 'grossAmount' => $this->eur('10200.00'), 'legs' => [
            ['direction' => 'OUT', 'instrumentId' => self::FUND, 'amount' => $this->eur('6000.00')],
            ['direction' => 'OUT', 'instrumentId' => self::ETF, 'quantity' => '40', 'unitPrice' => '105.00', 'amount' => $this->eur('4200.00')],
        ]];
    }

    protected function createContract(): void
    {
        $this->send('PUT', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract', [
            'insurer' => 'Assureur Démo', 'subscribedOn' => '2026-01-01', 'managementMode' => 'FREE', 'contractNumber' => 'AV-00012345',
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array{value: string, assetCode: string} */
    protected function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
    }

    protected function operations(string $account): string
    {
        return '/api/v1/accounts/'.$account.'/portfolio-operations';
    }

    /** @param array<string, mixed>|null $body */
    protected function send(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function rows(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    /** @param array<string, mixed> $body */
    protected static function amountValue(array $body, string $field): string
    {
        return self::string(self::map($body[$field])['value']);
    }

    /** @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    protected static function legs(array $body): array
    {
        $rows = $body['legs'];
        self::assertIsArray($rows);

        return array_values(array_map(self::map(...), $rows));
    }

    /** @return array<string, mixed> */
    protected static function map(mixed $body): array
    {
        self::assertIsArray($body);
        $typed = [];
        foreach ($body as $key => $value) {
            self::assertIsString($key);
            $typed[$key] = $value;
        }

        return $typed;
    }

    protected static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, mixed> */
    protected function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $typed = [];
        foreach ($decoded as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }
}

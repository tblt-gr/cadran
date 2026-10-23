<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class MonthlyBoundsControllerTest extends WebTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000b1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000b9';
    private const string URI = '/api/v1/reports/monthly/bounds';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->refreshCsrf();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAnEmptyWorkspaceHasNoBoundaryAndTheCurrentMonthIsResolvedInItsTimezone(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);

        self::assertSame(['firstDataMonth' => null, 'currentMonth' => '2026-10'], $this->read());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function testNovemberInceptionIsTheEarliestOwnBookedTransaction(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE);
        $this->transaction('01', self::OTHER_ACCOUNT, 'BOOKED', '2010-03-01', WorkspaceFixture::OTHER_WORKSPACE);
        $this->transaction('02', self::ACCOUNT, 'BOOKED', '2023-11-17');
        $this->transaction('03', self::ACCOUNT, 'BOOKED', '2026-02-01');

        self::assertSame('2023-11', $this->read()['firstDataMonth']);
    }

    public function testASnapshotOlderThanTheFirstTransactionStartsTheRange(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->transaction('02', self::ACCOUNT, 'BOOKED', '2023-11-17');
        $this->snapshot('2022-06-30');

        self::assertSame('2022-06', $this->read()['firstDataMonth']);
    }

    public function testVoidedPendingAndFutureOnlySourcesNeverFormABoundary(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE);
        $this->transaction('01', self::ACCOUNT, 'VOIDED', '2019-01-10');
        $this->transaction('02', self::ACCOUNT, 'PENDING', '2020-01-10');
        $this->transaction('03', self::ACCOUNT, 'BOOKED', '2027-03-01');
        $this->snapshot('2027-04-01');

        self::assertNull($this->read()['firstDataMonth']);
    }

    public function testAnonymousCallersAreRejected(): void
    {
        $this->client->request('GET', self::URI);

        self::assertResponseStatusCodeSame(401);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        // 23:30 UTC on 30 September is already October in the workspace zone (Europe/Paris).
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-09-30T23:30:00+00:00'));
        $this->refreshCsrf();
    }

    private function refreshCsrf(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $this->client->request('GET', self::URI);
        self::assertResponseIsSuccessful();
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return array_combine(array_map(strval(...), array_keys($value)), $value);
    }

    private function account(string $id, string $workspace): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => 'Courant', 'asset_code' => 'EUR', 'kind' => 'CURRENT',
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2000-01-01', 'version' => 1,
            'created_at' => '2000-01-01 12:00:00+00', 'updated_at' => '2000-01-01 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function snapshot(string $day): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($day), 0, 8).'-0000-7000-8000-000000000000',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => self::ACCOUNT, 'as_of' => $day,
            'amount_value' => '100.00', 'amount_literal' => '100.00', 'amount_asset' => 'EUR', 'source' => 'MANUAL',
            'reconciliation_status' => 'RECONCILED', 'version' => 1, 'recorded_at' => $day.' 12:00:00+00',
            'recorded_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function transaction(string $suffix, string $account, string $state, string $bookedOn, string $workspace = WorkspaceFixture::OWN_WORKSPACE): void
    {
        $voided = 'VOIDED' === $state ? ['voided_at' => $bookedOn.' 13:00:00+00'] : [];
        $this->connection->insert('transaction_transactions', [
            'id' => '00000000-0000-7000-8000-0000000005'.$suffix, 'workspace_id' => $workspace, 'account_id' => $account,
            'asset_code' => 'EUR', 'amount_value' => '-5.00', 'amount_scale' => 2, 'state' => $state, 'nature' => 'EXPENSE',
            'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'Bounds '.$suffix,
            'version' => 1, 'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
        ] + $voided);
    }
}

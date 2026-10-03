<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PortfolioCashControllerTest extends WebTestCase
{
    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string ETF = PortfolioFixture::ETF;
    private const string CURRENT_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';
    private const string LEG_IN = '00000000-0000-7000-8000-0000000000e1';
    private const string LEG_OUT = '00000000-0000-7000-8000-0000000000e2';
    private const string STRAY_EXPENSE = '00000000-0000-7000-8000-0000000000e6';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private PortfolioFixture $portfolio;
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

        $this->portfolio = new PortfolioFixture($connection);
        $this->portfolio->account();
        $this->portfolio->account(self::CURRENT_ACCOUNT, label: 'Compte courant', kind: 'CURRENT', valuationMode: 'TRANSACTIONS');
        $this->portfolio->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->instrument(self::ETF, name: 'ETF Monde', isin: 'IE00B4L5Y983');

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

    public function testTheReferenceSequenceYieldsTheExactRunningCashTotalsAndClosingIdentity(): void
    {
        $this->recordReferenceSequence();

        $cash = $this->cash('2026-03-01', '2026-03-31');

        self::assertSame(
            ['from', 'to', 'assetCode', 'openingBalance', 'closingBalance', 'currentBalance', 'totals', 'movements', 'warnings', 'costPolicy'],
            array_keys($cash),
        );
        self::assertSame('EUR', $cash['assetCode']);
        self::assertSame('0', self::value($cash, 'openingBalance'));
        self::assertSame('1193.28', self::value($cash, 'closingBalance'));
        self::assertSame('1193.28', self::value($cash, 'currentBalance'));
        self::assertSame(
            ['3000.00', '692.32', '701.07', '696.07', '694.87', '1693.28', '1193.28'],
            array_map(static fn (array $movement): string => self::value($movement, 'runningBalance'), self::movements($cash)),
        );
        self::assertSame(
            ['DEPOSIT', 'BUY', 'DIVIDEND', 'FEE', 'TAX', 'SELL', 'WITHDRAWAL'],
            array_column(self::movements($cash), 'type'),
        );
        self::assertSame(['operationId', 'tradeDate', 'sequence', 'type', 'cashAmount', 'runningBalance'], array_keys(self::movements($cash)[0]));
        self::assertSame('-2307.68', self::value(self::movements($cash)[1], 'cashAmount'));
        $totals = self::map($cash['totals']);
        self::assertSame(
            ['deposits', 'withdrawals', 'buysGross', 'sellsGross', 'dividendsGross', 'interestGross', 'fees', 'taxes', 'cashInLieu', 'roundingResiduals'],
            array_keys($totals),
        );
        self::assertSame('8.98', self::value($totals, 'fees'));
        self::assertSame('4.95', self::value($totals, 'taxes'));
        self::assertSame('3000.00', self::value($totals, 'deposits'));
        self::assertSame('500.00', self::value($totals, 'withdrawals'));
        self::assertSame(['performanceBasis' => 'NET_OF_FEES_AND_TAXES', 'budgetTreatment' => 'OUTSIDE_BUDGET_INFORMATIVE'], $cash['costPolicy']);
        self::assertSame($cash['closingBalance'], ['value' => self::identity($cash), 'assetCode' => 'EUR']);
    }

    public function testThePeriodOpensOnTheCashOfEarlierOperationsAndCurrentBalanceIgnoresTheRange(): void
    {
        $this->recordReferenceSequence();

        $cash = $this->cash('2026-03-04', '2026-03-05');

        self::assertSame('701.07', self::value($cash, 'openingBalance'));
        self::assertSame('694.87', self::value($cash, 'closingBalance'));
        self::assertSame('1193.28', self::value($cash, 'currentBalance'));
        self::assertCount(2, self::movements($cash));
        self::assertSame('5.00', self::value(self::map($cash['totals']), 'fees'));
        self::assertSame('694.87', self::identity($cash));
    }

    public function testAnEmptyPeriodReturnsZerosAndNoWarnings(): void
    {
        $cash = $this->cash('2026-03-01', '2026-03-31');

        self::assertSame('0', self::value($cash, 'closingBalance'));
        self::assertSame('0', self::value($cash, 'currentBalance'));
        self::assertSame([], $cash['movements']);
        self::assertSame([], $cash['warnings']);
    }

    public function testAVoidedOperationIsExcludedFromBalancesAndTotals(): void
    {
        $this->recordReferenceSequence();
        $fee = $this->connection->fetchOne("SELECT id FROM portfolio_operations WHERE operation_type = 'FEE'");
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($fee).'/void', ['version' => 1, 'reason' => 'Erreur']);
        self::assertResponseStatusCodeSame(200);

        $cash = $this->cash('2026-03-01', '2026-03-31');

        self::assertSame('1198.28', self::value($cash, 'closingBalance'));
        self::assertSame('3.98', self::value(self::map($cash['totals']), 'fees'));
        self::assertCount(6, self::movements($cash));
    }

    public function testEveryWarningIsNamedWithItsCountAndSourcesAndNoneBlocksTheRead(): void
    {
        $this->portfolio->transferLeg(self::LEG_IN, self::ACCOUNT, '500.00', '2026-03-02');
        $this->portfolio->transferLeg(self::LEG_OUT, self::ACCOUNT, '-200.00', '2026-03-03');
        $this->portfolio->transferLeg(self::STRAY_EXPENSE, self::ACCOUNT, '-9.00', '2026-03-05', nature: 'EXPENSE');
        $linkedLeg = '00000000-0000-7000-8000-0000000000e7';
        $this->portfolio->transferLeg($linkedLeg, self::ACCOUNT, '40.00', '2026-03-06');
        $this->record(['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-01', 'grossAmount' => $this->eur('10.00')]);
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-06', 'grossAmount' => $this->eur('40.00'), 'linkedTransactionId' => $linkedLeg]);
        $this->record($this->buy(['tradeDate' => '2026-03-07', 'quantity' => '1', 'unitPrice' => '10.004', 'grossAmount' => $this->eur('10.00'), 'fees' => null]));
        $this->connection->executeStatement("UPDATE transaction_transactions SET state = 'VOIDED', voided_at = now() WHERE id = ?", [$linkedLeg]);

        $cash = $this->cash('2026-03-01', '2026-03-31');

        $warnings = self::warnings($cash);
        self::assertSame(
            ['NEGATIVE_CASH', 'UNLINKED_TRANSFER_IN', 'UNLINKED_TRANSFER_OUT', 'UNLINKED_EXTERNAL_FLOW', 'LINKED_TRANSFER_CHANGED', 'NON_TRANSFER_TRANSACTION', 'ROUNDING_RESIDUALS'],
            array_column($warnings, 'code'),
        );
        $byCode = array_column($warnings, null, 'code');
        self::assertSame(['code', 'count', 'sourceIds', 'firstDate', 'amount'], array_keys($warnings[0]));
        self::assertSame('2026-03-01', $byCode['NEGATIVE_CASH']['firstDate']);
        self::assertSame([self::LEG_IN], $byCode['UNLINKED_TRANSFER_IN']['sourceIds']);
        self::assertSame([self::LEG_OUT], $byCode['UNLINKED_TRANSFER_OUT']['sourceIds']);
        self::assertSame(1, $byCode['UNLINKED_EXTERNAL_FLOW']['count']);
        self::assertSame(1, $byCode['LINKED_TRANSFER_CHANGED']['count']);
        self::assertSame([self::STRAY_EXPENSE], $byCode['NON_TRANSFER_TRANSACTION']['sourceIds']);
        self::assertSame(['value' => '-0.004', 'assetCode' => 'EUR'], $byCode['ROUNDING_RESIDUALS']['amount']);
        self::assertNull($byCode['UNLINKED_TRANSFER_IN']['firstDate']);
        self::assertNull($byCode['UNLINKED_TRANSFER_IN']['amount']);
    }

    public function testTheRangeIsBoundedAndNeverInTheFuture(): void
    {
        $this->get('2026-01-01', '2027-01-02');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('cash_range_too_large', $this->decode()['code']);

        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->get($today->modify('-10 days')->format('Y-m-d'), $today->modify('+2 days')->format('Y-m-d'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_cash_query', $this->decode()['code']);

        $this->get('2026-03-02', '2026-03-01');
        self::assertResponseStatusCodeSame(422);
        $this->get('2026-02-30', '2026-03-01');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-cash?from[]=2026-03-01', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $this->get('2026-01-01', '2026-03-31');
        self::assertResponseIsSuccessful();
    }

    public function testAYearOfExactly366DaysIsAcceptedAndOneDayMoreIsNot(): void
    {
        $this->get('2025-01-01', '2026-01-02');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('cash_range_too_large', $this->decode()['code']);
        $this->get('2025-01-01', '2026-01-01');
        self::assertResponseIsSuccessful();
    }

    public function testTheDefaultPeriodIsTheCurrentYearUntilToday(): void
    {
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-cash', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $cash = $this->decode();
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::assertSame($today->format('Y').'-01-01', $cash['from']);
        self::assertContains($cash['to'], [$today->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d')]);
    }

    public function testARangeHoldingMoreThanTwoThousandMovementsAsksToNarrowIt(): void
    {
        $this->insertOperations(2001);

        $this->get('2026-03-01', '2026-03-31');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('cash_range_too_dense', $this->decode()['code']);

        $this->insertOperations(0);
        $this->get('2026-04-01', '2026-04-30');
        self::assertResponseIsSuccessful();
        self::assertSame('2001', self::value($this->decode(), 'openingBalance'), 'earlier operations still build the opening balance');
    }

    public function testMoreThanTwoThousandTransactionsInThePeriodAlsoAskToNarrowItInsteadOfTruncatingWarnings(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO transaction_transactions (id, workspace_id, account_id, asset_code, amount_value, amount_scale, state, nature, source, booked_on, raw_label, version, created_at, updated_at)
             SELECT ('00000000-0000-7000-8002-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'EUR', -1, 0, 'BOOKED', 'EXPENSE', 'MANUAL', DATE '2026-03-10', 'Ancien', 1, now(), now()
             FROM generate_series(1, 2001) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT],
        );

        $this->get('2026-03-01', '2026-03-31');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('cash_range_too_dense', $this->decode()['code']);
        $this->get('2026-04-01', '2026-04-30');
        self::assertResponseIsSuccessful();
    }

    public function testVeryLargeAmountsStayExactAndAnOverflowIsRefusedNotWrapped(): void
    {
        $large = '99999999999999999999999999.00';
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur($large)]);
        $cash = $this->cash('2026-03-01', '2026-03-31');
        self::assertSame($large, self::value($cash, 'closingBalance'));

        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-03', 'grossAmount' => $this->eur($large)]);
        $this->get('2026-03-01', '2026-03-31');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('cash_amount_too_large', $this->decode()['code']);
    }

    public function testAnotherWorkspacesUnknownOrNonPortfolioAccountsAreNotReadable(): void
    {
        $this->client->request('GET', '/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-cash', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/v1/accounts/00000000-0000-7000-8000-0000000000ff/portfolio-cash', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/accounts/not-an-id/portfolio-cash', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/accounts/'.self::CURRENT_ACCOUNT.'/portfolio-cash', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('account_not_portfolio', $this->decode()['code']);
    }

    public function testASignedOutRequestIsRefused(): void
    {
        $this->client->getCookieJar()->clear();
        $this->get('2026-03-01', '2026-03-31');
        self::assertResponseStatusCodeSame(401);
    }

    private function recordReferenceSequence(): void
    {
        $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('3000.00')]);
        $this->record($this->buy());
        $this->record(['type' => 'DIVIDEND', 'tradeDate' => '2026-03-03', 'instrumentId' => self::ETF, 'quantity' => '10', 'grossAmount' => $this->eur('12.50'), 'taxes' => $this->eur('3.75')]);
        $this->record(['type' => 'FEE', 'tradeDate' => '2026-03-04', 'fees' => $this->eur('5.00')]);
        $this->record(['type' => 'TAX', 'tradeDate' => '2026-03-05', 'taxes' => $this->eur('1.20')]);
        $this->record(['type' => 'SELL', 'tradeDate' => '2026-03-06', 'instrumentId' => self::ETF, 'quantity' => '4', 'unitPrice' => '250.10', 'grossAmount' => $this->eur('1000.40'), 'fees' => $this->eur('1.99')]);
        $this->record(['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-07', 'grossAmount' => $this->eur('500.00')]);
    }

    private function insertOperations(int $count): void
    {
        if (0 === $count) {
            return;
        }
        $this->connection->executeStatement(
            "INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code, gross_amount_value, gross_amount_scale,
                fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, state, created_at, created_by, version)
             SELECT ('00000000-0000-7000-8001-' || lpad(n::text, 12, '0'))::uuid, :ws, :account, 'DEPOSIT', DATE '2026-03-10', n, 'EUR', 1, 0, 0, 0, 0, 0, 1, 0,
                'ACTIVE', now(), :owner, 1 FROM generate_series(1, :count) AS n",
            ['ws' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::ACCOUNT, 'owner' => WorkspaceFixture::OWNER_ID, 'count' => $count],
        );
    }

    /** @return array<string, mixed> */
    private function cash(string $from, string $to): array
    {
        $this->get($from, $to);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    private function get(string $from, string $to): void
    {
        $this->client->request('GET', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-cash?from='.$from.'&to='.$to, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buy(array $overrides = []): array
    {
        return [
            'type' => 'BUY', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '10', 'unitPrice' => '230.5688',
            'grossAmount' => $this->eur('2305.69'), 'fees' => $this->eur('1.99'), ...$overrides,
        ];
    }

    /** @param array<string, mixed> $body */
    private function record(array $body): void
    {
        $body = array_filter($body, static fn (mixed $value): bool => null !== $value);
        /* @var array<string, mixed> $body */
        $this->send('POST', '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations', $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** @return array{value: string, assetCode: string} */
    private function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
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

        return self::map($decoded);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $typed[$key] = $item;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $cash
     *
     * @return list<array<string, mixed>>
     */
    private static function movements(array $cash): array
    {
        self::assertIsList($cash['movements']);

        return array_map(self::map(...), $cash['movements']);
    }

    /**
     * @param array<string, mixed> $cash
     *
     * @return list<array<string, mixed>>
     */
    private static function warnings(array $cash): array
    {
        self::assertIsList($cash['warnings']);

        return array_map(self::map(...), $cash['warnings']);
    }

    /** @param array<string, mixed> $holder */
    private static function value(array $holder, string $field): string
    {
        $amount = self::map($holder[$field] ?? null);
        self::assertSame('EUR', $amount['assetCode']);

        return self::string($amount['value']);
    }

    /** @param array<string, mixed> $cash the closing identity, recomputed from the stated components */
    private static function identity(array $cash): string
    {
        $totals = self::map($cash['totals']);
        $result = BigDecimal::of(self::value($cash, 'openingBalance'));
        foreach (['deposits' => 1, 'withdrawals' => -1, 'buysGross' => -1, 'sellsGross' => 1, 'dividendsGross' => 1, 'interestGross' => 1, 'fees' => -1, 'taxes' => -1, 'cashInLieu' => 1] as $field => $sign) {
            $component = BigDecimal::of(self::value($totals, $field));
            $result = $sign > 0 ? $result->plus($component) : $result->minus($component);
        }

        return (string) $result;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}

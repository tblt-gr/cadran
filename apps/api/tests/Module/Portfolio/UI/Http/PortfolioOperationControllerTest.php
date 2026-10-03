<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PortfolioOperationControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string ACCOUNT = PortfolioFixture::ACCOUNT;
    private const string ETF = PortfolioFixture::ETF;
    private const string STOCK = PortfolioFixture::STOCK;
    private const string USD_INSTRUMENT = '00000000-0000-7000-8000-000000000b03';
    private const string INACTIVE_INSTRUMENT = '00000000-0000-7000-8000-000000000b04';
    private const string FOREIGN_INSTRUMENT = '00000000-0000-7000-8000-000000000b05';
    private const string UNKNOWN = '00000000-0000-7000-8000-0000000000ff';
    private const string CURRENT_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string ARCHIVED_ACCOUNT = '00000000-0000-7000-8000-0000000000d5';
    private const string CLOSED_ACCOUNT = '00000000-0000-7000-8000-0000000000d6';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d7';
    private const string TRANSACTIONS_MODE_ACCOUNT = '00000000-0000-7000-8000-0000000000d8';
    private const string LEG_IN = '00000000-0000-7000-8000-0000000000e1';
    private const string LEG_OUT = '00000000-0000-7000-8000-0000000000e2';
    private const string FOREIGN_LEG = '00000000-0000-7000-8000-0000000000e3';
    private const string OTHER_ACCOUNT_LEG = '00000000-0000-7000-8000-0000000000e4';
    private const string VOIDED_LEG = '00000000-0000-7000-8000-0000000000e5';

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
        $this->portfolio->account(self::TRANSACTIONS_MODE_ACCOUNT, label: 'Titres en snapshots', valuationMode: 'SNAPSHOTS');
        $this->portfolio->account(self::ARCHIVED_ACCOUNT, label: 'Archivé', archived: true);
        $this->portfolio->account(self::CLOSED_ACCOUNT, label: 'Clôturé', closedOn: '2026-02-01');
        $this->portfolio->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin');
        $this->portfolio->instrument(self::ETF, name: 'ETF Monde', isin: 'IE00B4L5Y983');
        $this->portfolio->instrument(self::STOCK, name: 'Action Industrie', isin: 'FR0000120271', quantityPrecision: 0, pricePrecision: 2);
        $this->portfolio->instrument(self::USD_INSTRUMENT, name: 'Apple', isin: 'US0378331005', currency: 'USD');
        $this->portfolio->instrument(self::INACTIVE_INSTRUMENT, name: 'Fermé', isin: 'FR0010315770', status: 'INACTIVE');
        $this->portfolio->instrument(self::FOREIGN_INSTRUMENT, WorkspaceFixture::OTHER_WORKSPACE, 'Privé', 'DE0007164600');
        $this->portfolio->transferLeg(self::LEG_IN, self::ACCOUNT, '500.00', '2026-03-02');
        $this->portfolio->transferLeg(self::LEG_OUT, self::ACCOUNT, '-200.00', '2026-03-03');
        $this->portfolio->transferLeg(self::VOIDED_LEG, self::ACCOUNT, '500.00', '2026-03-02', state: 'VOIDED');
        $this->portfolio->transferLeg(self::OTHER_ACCOUNT_LEG, self::CURRENT_ACCOUNT, '500.00', '2026-03-02');
        $this->portfolio->transferLeg(self::FOREIGN_LEG, self::FOREIGN_ACCOUNT, '500.00', '2026-03-02', WorkspaceFixture::OTHER_WORKSPACE);

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

    public function testTheReferenceBuyIsStoredWithItsResidualAndDerivedCashEffect(): void
    {
        $buy = $this->record($this->buy());

        self::assertSame(self::ACCOUNT, $buy['accountId']);
        self::assertSame('BUY', $buy['type']);
        self::assertSame('2026-03-02', $buy['tradeDate']);
        self::assertSame(1, $buy['sequence']);
        self::assertSame(1, $buy['activeDayOperationCount']);
        self::assertSame(['id' => self::ETF, 'name' => 'ETF Monde', 'isin' => 'IE00B4L5Y983', 'ticker' => null, 'status' => 'ACTIVE'], $buy['instrument']);
        self::assertSame('10', $buy['quantity']);
        self::assertSame('230.5688', $buy['unitPrice']);
        self::assertSame(['value' => '2305.69', 'assetCode' => 'EUR'], $buy['grossAmount']);
        self::assertSame(['value' => '1.99', 'assetCode' => 'EUR'], $buy['fees']);
        self::assertSame(['value' => '-2307.68', 'assetCode' => 'EUR'], $buy['cashAmount']);
        self::assertSame(['value' => '0.002', 'assetCode' => 'EUR'], $buy['roundingResidual']);
        self::assertSame('ACTIVE', $buy['state']);
        self::assertSame(1, $buy['version']);
        self::assertNull($buy['voidedAt']);
        self::assertNull($buy['correctsOperationId']);
        self::assertNull($buy['correctedByOperationId']);
        self::assertSame(
            ['id', 'accountId', 'type', 'tradeDate', 'settlementDate', 'sequence', 'activeDayOperationCount', 'instrument', 'counterInstrument', 'quantity', 'unitPrice', 'grossAmount', 'fees', 'taxes', 'cashAmount', 'costBasis', 'counterQuantity', 'ratio', 'costAllocationRatio', 'roundingResidual', 'reference', 'note', 'linkedTransactionId', 'state', 'voidedAt', 'voidReason', 'voidKind', 'correctsOperationId', 'correctedByOperationId', 'version'],
            array_keys($buy),
        );

        $this->get('/api/v1/portfolio-operations/'.self::string($buy['id']));
        self::assertResponseIsSuccessful();
        self::assertSame($buy, $this->decode());
        self::assertNotNull($this->connection->fetchOne('SELECT used_at FROM portfolio_instruments WHERE id = ?', [self::ETF]));
        self::assertNotNull($this->connection->fetchOne('SELECT used_at FROM account_financial_accounts WHERE id = ?', [self::ACCOUNT]));
    }

    public function testTheReferenceBuyBeyondTheToleranceIsRefusedWithTheExpectedGross(): void
    {
        $this->post($this->buy(['grossAmount' => ['value' => '2305.70', 'assetCode' => 'EUR']]));

        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('operation_amount_mismatch', $problem['code']);
        self::assertSame('2305.688', $problem['expectedGrossAmount']);
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    public function testASellAndADividendDeriveTheirExactCashEffect(): void
    {
        $this->record($this->buy(['quantity' => '4', 'unitPrice' => '250.10', 'grossAmount' => $this->eur('1000.40')]));
        $sell = $this->record([
            'type' => 'SELL', 'tradeDate' => '2026-03-04', 'instrumentId' => self::ETF, 'quantity' => '4', 'unitPrice' => '250.10',
            'grossAmount' => ['value' => '1000.40', 'assetCode' => 'EUR'], 'fees' => ['value' => '1.99', 'assetCode' => 'EUR'],
        ]);
        self::assertSame('998.41', self::amountValue($sell, 'cashAmount'));
        self::assertSame('0', self::amountValue($sell, 'roundingResidual'));

        $dividend = $this->record([
            'type' => 'DIVIDEND', 'tradeDate' => '2026-03-05', 'instrumentId' => self::ETF,
            'grossAmount' => ['value' => '12.50', 'assetCode' => 'EUR'], 'taxes' => ['value' => '3.75', 'assetCode' => 'EUR'],
        ]);
        self::assertSame('8.75', self::amountValue($dividend, 'cashAmount'));
        self::assertNull($dividend['roundingResidual']);
        self::assertSame(['value' => '0', 'assetCode' => 'EUR'], $dividend['fees']);
    }

    public function testThePreviewReturnsTheServerDerivedFiguresAndWritesNothing(): void
    {
        $this->send('POST', $this->path('/preview'), $this->buy());
        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'cashAmount' => ['value' => '-2307.68', 'assetCode' => 'EUR'],
            'roundingResidual' => ['value' => '0.002', 'assetCode' => 'EUR'],
            'expectedGrossAmount' => ['value' => '2305.688', 'assetCode' => 'EUR'],
        ], $this->decode());
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertNull($this->connection->fetchOne('SELECT used_at FROM portfolio_instruments WHERE id = ?', [self::ETF]));
        self::assertNull($this->connection->fetchOne('SELECT used_at FROM account_financial_accounts WHERE id = ?', [self::ACCOUNT]));

        $this->send('POST', $this->path('/preview'), $this->buy(['grossAmount' => ['value' => '2305.70', 'assetCode' => 'EUR']]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('operation_amount_mismatch', $this->decode()['code']);

        $this->send('POST', $this->path('/preview'), ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']]);
        self::assertSame(['cashAmount' => ['value' => '10', 'assetCode' => 'EUR'], 'roundingResidual' => null, 'expectedGrossAmount' => null], $this->decode());
    }

    public function testEveryOperationTypeCanBeRecordedAndNeverTouchesTheBudget(): void
    {
        $this->get('/api/v1/reports/monthly?month=2026-03');
        $before = (string) $this->client->getResponse()->getContent();
        $transactions = $this->rows('transaction_transactions');

        foreach ([
            ['type' => 'DEPOSIT', 'grossAmount' => $this->eur('1000')],
            ['type' => 'WITHDRAWAL', 'grossAmount' => $this->eur('10')],
            $this->buy(),
            ['type' => 'SELL', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '100', 'grossAmount' => $this->eur('100')],
            ['type' => 'DIVIDEND', 'instrumentId' => self::ETF, 'quantity' => '9', 'grossAmount' => $this->eur('5.00'), 'taxes' => $this->eur('1.50')],
            ['type' => 'INTEREST', 'grossAmount' => $this->eur('0.40')],
            ['type' => 'FEE', 'fees' => $this->eur('1.00'), 'instrumentId' => self::ETF],
            ['type' => 'TAX', 'taxes' => $this->eur('2.00')],
            ['type' => 'SECURITY_TRANSFER_IN', 'instrumentId' => self::STOCK, 'quantity' => '3', 'costBasis' => $this->eur('90')],
            ['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::STOCK, 'quantity' => '1'],
            ['type' => 'SPLIT', 'instrumentId' => self::STOCK, 'ratio' => ['numerator' => '2', 'denominator' => '1']],
            ['type' => 'MERGER', 'instrumentId' => self::STOCK, 'counterInstrumentId' => self::ETF, 'counterQuantity' => '1.5', 'grossAmount' => $this->eur('3.20')],
            ['type' => 'SPIN_OFF', 'instrumentId' => self::ETF, 'counterInstrumentId' => self::STOCK, 'counterQuantity' => '2', 'costAllocationRatio' => '0.25'],
        ] as $index => $body) {
            $created = $this->record([...$body, 'tradeDate' => '2026-03-06']);
            self::assertSame($index + 1, $created['sequence'], 'sequence follows the day');
        }

        self::assertSame(13, $this->rows('portfolio_operations'));
        self::assertSame($transactions, $this->rows('transaction_transactions'));
        $this->get('/api/v1/reports/monthly?month=2026-03');
        self::assertSame($before, (string) $this->client->getResponse()->getContent(), 'the monthly projection never moves');
        self::assertSame(
            ['1000', '-10', '-2307.68', '100', '3.5', '0.4', '-1', '-2', '0', '0', '0', '3.2', '0'],
            array_map(fn (mixed $cash): string => $this->trimDecimal(is_string($cash) ? $cash : ''), $this->connection->fetchFirstColumn('SELECT cash_amount_value::text FROM portfolio_operations ORDER BY sequence')),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string, string|null}> */
    public static function invalidBodies(): iterable
    {
        yield 'forbidden field' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR'], 'quantity' => '1'], 'operation_field_not_allowed', 'quantity'];
        yield 'missing field' => [['type' => 'BUY', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '1'], 'operation_field_required', 'grossAmount'];
        yield 'negative amount' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '-10', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'zero amount' => [['type' => 'WITHDRAWAL', 'grossAmount' => ['value' => '0', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'zero fee operation' => [['type' => 'FEE', 'fees' => ['value' => '0', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'negative quantity' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::ETF, 'quantity' => '-1'], 'invalid_operation_quantity', null];
        yield 'quantity precision' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::ETF, 'quantity' => '1.1234567'], 'quantity_precision_exceeded', null];
        yield 'quantity precision zero' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::STOCK, 'quantity' => '1.5'], 'quantity_precision_exceeded', null];
        yield 'price precision' => [['type' => 'BUY', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '10.12345', 'grossAmount' => ['value' => '10.12', 'assetCode' => 'EUR']], 'price_precision_exceeded', null];
        yield 'amount with 25 decimals' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '1.1234567890123456789012345', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'amount with currency precision exceeded' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '1.123456789', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'exponent literal' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '1e3', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'plus sign literal' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '+10', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'exponent quantity' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::ETF, 'quantity' => '1e3'], 'invalid_operation_quantity', null];
        yield 'huge amount' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => str_repeat('9', 27), 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'huge cash effect' => [['type' => 'BUY', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => str_repeat('9', 26), 'grossAmount' => ['value' => str_repeat('9', 26), 'assetCode' => 'EUR'], 'fees' => ['value' => '1', 'assetCode' => 'EUR']], 'invalid_operation_amount', null];
        yield 'foreign currency amount' => [['type' => 'DEPOSIT', 'grossAmount' => ['value' => '10', 'assetCode' => 'USD']], 'operation_asset_mismatch', null];
        yield 'inactive instrument' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::INACTIVE_INSTRUMENT, 'quantity' => '1'], 'instrument_inactive', null];
        yield 'instrument in another currency' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::USD_INSTRUMENT, 'quantity' => '1'], 'instrument_currency_mismatch', null];
        yield 'unknown instrument' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::UNKNOWN, 'quantity' => '1'], 'unknown_instrument', null];
        yield 'foreign instrument' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::FOREIGN_INSTRUMENT, 'quantity' => '1'], 'unknown_instrument', null];
        yield 'counter equals instrument' => [['type' => 'MERGER', 'instrumentId' => self::ETF, 'counterInstrumentId' => self::ETF, 'counterQuantity' => '1'], 'invalid_corporate_action', null];
        yield 'equal split ratio' => [['type' => 'SPLIT', 'instrumentId' => self::ETF, 'ratio' => ['numerator' => '3', 'denominator' => '3']], 'invalid_corporate_action', null];
        yield 'date before opening' => [['type' => 'DEPOSIT', 'tradeDate' => '2025-12-31', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_operation_date', null];
        yield 'date in the future' => [['type' => 'DEPOSIT', 'tradeDate' => '2999-01-01', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_operation_date', null];
        yield 'impossible date' => [['type' => 'DEPOSIT', 'tradeDate' => '2026-02-30', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_operation_date', null];
        yield 'settlement before trade' => [['type' => 'DEPOSIT', 'settlementDate' => '2026-03-01', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_settlement_date', null];
        yield 'settlement too late' => [['type' => 'DEPOSIT', 'settlementDate' => '2026-04-05', 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_settlement_date', null];
        yield 'control character reference' => [['type' => 'DEPOSIT', 'reference' => "A\u{0007}B", 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_operation_text', null];
        yield 'oversized note' => [['type' => 'DEPOSIT', 'note' => str_repeat('n', 201), 'grossAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_operation_text', null];
        yield 'unsupported type' => [['type' => 'OTHER_CORPORATE_ACTION'], 'unsupported_operation_type', null];
        yield 'unknown type' => [['type' => 'GIFT'], 'invalid_operation_type', null];
        yield 'number instead of string' => [['type' => 'SECURITY_TRANSFER_OUT', 'instrumentId' => self::ETF, 'quantity' => 1], 'invalid_operation_payload', null];
        yield 'unknown field' => [['type' => 'DEPOSIT', 'cashAmount' => ['value' => '10', 'assetCode' => 'EUR']], 'invalid_operation_payload', null];
        yield 'malformed amount object' => [['type' => 'DEPOSIT', 'grossAmount' => '10'], 'invalid_operation_payload', null];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testARefusedOperationReportsItsCodeAndWritesNothing(array $body, string $code, ?string $field): void
    {
        $this->post(['tradeDate' => '2026-03-02', ...$body]);

        self::assertResponseStatusCodeSame(422, $code);
        $problem = $this->decode();
        self::assertSame($code, $problem['code']);
        if (null !== $field) {
            self::assertSame($field, $problem['field']);
        }
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertNull($this->connection->fetchOne('SELECT used_at FROM portfolio_instruments WHERE id = ?', [self::ETF]));
    }

    public function testTheForeignInstrumentAnswersLikeAnUnknownOne(): void
    {
        $answer = static function (self $test, string $id): string {
            $test->post(['type' => 'SECURITY_TRANSFER_OUT', 'tradeDate' => '2026-03-02', 'instrumentId' => $id, 'quantity' => '1']);

            return (string) $test->client->getResponse()->getContent();
        };

        self::assertSame($answer($this, self::UNKNOWN), $answer($this, self::FOREIGN_INSTRUMENT));
    }

    public function testOnlyAnEligiblePortfolioAccountAcceptsOperations(): void
    {
        $body = ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('10')];
        foreach ([
            [self::CURRENT_ACCOUNT, 422, 'account_not_portfolio'],
            [self::TRANSACTIONS_MODE_ACCOUNT, 422, 'account_not_portfolio'],
            [self::ARCHIVED_ACCOUNT, 422, 'portfolio_account_inactive'],
            [self::CLOSED_ACCOUNT, 422, 'portfolio_account_inactive'],
            [self::FOREIGN_ACCOUNT, 404, null],
            [self::UNKNOWN, 404, null],
        ] as [$account, $status, $code]) {
            $this->send('POST', '/api/v1/accounts/'.$account.'/portfolio-operations', $body);
            self::assertResponseStatusCodeSame($status, $account);
            if (null !== $code) {
                self::assertSame($code, $this->decode()['code']);
            }
            // History stays readable on a closed or archived portfolio account.
            $this->get('/api/v1/accounts/'.$account.'/portfolio-operations');
            self::assertResponseStatusCodeSame('portfolio_account_inactive' === $code ? 200 : $status, $account);
        }
        self::assertSame(0, $this->rows('portfolio_operations'));

        $this->send('POST', '/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-operations', $body);
        $foreign = (string) $this->client->getResponse()->getContent();
        $this->send('POST', '/api/v1/accounts/'.self::UNKNOWN.'/portfolio-operations', $body);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent(), 'a foreign account answers exactly like an unknown one');
        $this->get('/api/v1/accounts/not-a-uuid/portfolio-operations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testASecondIdenticalRequestWithTheSameKeyReplaysTheFirst(): void
    {
        $body = $this->buy();
        $this->post($body, 'key-create-0000000001');
        self::assertResponseStatusCodeSame(201);
        $first = $this->decode();
        self::assertNull($this->client->getResponse()->headers->get('Idempotency-Replayed'));

        $this->post($body, 'key-create-0000000001');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertEquals($first, $this->decode(), 'the replay is the first answer, stored as JSON');
        self::assertSame(1, $this->rows('portfolio_operations'));

        $this->post($this->buy(['quantity' => '11', 'grossAmount' => $this->eur('2536.26')]), 'key-create-0000000001');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->rows('portfolio_operations'));

        $this->post($body, 'short');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnOperationIsVoidedWithAReasonAndKeepsItsRowVisible(): void
    {
        $buy = $this->record($this->buy());
        $id = self::string($buy['id']);

        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_operation_text', $this->decode()['code']);
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 1, 'reason' => '   ']);
        self::assertResponseStatusCodeSame(422);

        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 7, 'reason' => 'Doublon']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_operation_version', $this->decode()['code']);

        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 1, 'reason' => ' Doublon ']);
        self::assertResponseStatusCodeSame(200);
        $voided = $this->decode();
        self::assertSame('VOIDED', $voided['state']);
        self::assertSame('VOIDED', $voided['voidKind']);
        self::assertSame('Doublon', $voided['voidReason']);
        self::assertNotNull($voided['voidedAt']);
        self::assertSame(2, $voided['version']);
        self::assertSame($buy['grossAmount'], $voided['grossAmount']);

        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 2, 'reason' => 'Encore']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('operation_voided', $this->decode()['code']);
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 2, 'operation' => $this->buy()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('operation_voided', $this->decode()['code']);

        $this->get($this->path('?includeVoided=false'));
        self::assertSame([], $this->decode()['items']);
        $this->get($this->path('?includeVoided=true'));
        self::assertSame([$id], array_column(self::items($this->decode()), 'id'));
    }

    public function testModifyingAnOperationReplacesItAtomicallyAndKeepsTheOriginalInTheHistory(): void
    {
        $buy = $this->record($this->buy());
        $id = self::string($buy['id']);

        $fixed = $this->buy(['quantity' => '11', 'grossAmount' => $this->eur('2536.26'), 'note' => 'Montant corrigé']);
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 1, 'operation' => $fixed], ['HTTP_IDEMPOTENCY_KEY' => 'key-correct-000000001']);
        self::assertResponseStatusCodeSame(201);
        $replacement = $this->decode();
        self::assertNotSame($id, $replacement['id']);
        self::assertSame($id, $replacement['correctsOperationId']);
        self::assertSame('ACTIVE', $replacement['state']);
        self::assertSame('11', $replacement['quantity']);
        self::assertSame('-2538.25', self::amountValue($replacement, 'cashAmount'));
        self::assertSame(1, $replacement['sequence'], 'the replacement takes the freed slot of the day');

        $this->get('/api/v1/portfolio-operations/'.$id);
        $original = $this->decode();
        self::assertSame('VOIDED', $original['state']);
        self::assertSame('REPLACED', $original['voidKind']);
        self::assertNull($original['voidReason']);
        self::assertSame($replacement['id'], $original['correctedByOperationId']);
        self::assertSame('10', $original['quantity']);

        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 2, 'operation' => $fixed]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('operation_voided', $this->decode()['code']);

        // The type may change, a reason may be given, and an idempotent retry replays.
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($replacement['id']).'/correct', [
            'version' => 1, 'reason' => 'C était un retrait', 'operation' => ['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('2538.25')],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('WITHDRAWAL', $this->decode()['type']);
        $this->get('/api/v1/portfolio-operations/'.self::string($replacement['id']));
        self::assertSame('C était un retrait', $this->decode()['voidReason']);

        $this->get($this->path());
        self::assertCount(1, self::items($this->decode()));
        $this->get($this->path('?includeVoided=true'));
        self::assertCount(3, self::items($this->decode()));
    }

    public function testAFailedReplacementLeavesTheOriginalActive(): void
    {
        $buy = $this->record($this->buy());
        $id = self::string($buy['id']);

        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 1, 'operation' => $this->buy(['grossAmount' => $this->eur('9999')])]);
        self::assertResponseStatusCodeSame(422);
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 9, 'operation' => $this->buy()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_operation_version', $this->decode()['code']);

        $this->get('/api/v1/portfolio-operations/'.$id);
        self::assertSame('ACTIVE', $this->decode()['state']);
        self::assertSame(1, $this->decode()['version']);
        self::assertSame(1, $this->rows('portfolio_operations'));
    }

    public function testADepositIsLinkedToTheBookedLegItMirrors(): void
    {
        $deposit = $this->record(['type' => 'DEPOSIT', 'grossAmount' => $this->eur('500.0'), 'linkedTransactionId' => self::LEG_IN]);
        self::assertSame(self::LEG_IN, $deposit['linkedTransactionId']);

        $this->post(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('linked_transfer_taken', $this->decode()['code']);

        foreach ([
            'amount' => ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('499.99'), 'linkedTransactionId' => self::LEG_IN],
            'date' => ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-03', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN],
            'direction' => ['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN],
            'withdrawal against an incoming leg' => ['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-03', 'grossAmount' => $this->eur('200.00'), 'linkedTransactionId' => self::LEG_IN],
            'another account' => ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::OTHER_ACCOUNT_LEG],
            'voided leg' => ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::VOIDED_LEG],
            'unknown leg' => ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::UNKNOWN],
            'foreign leg' => ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::FOREIGN_LEG],
        ] as $case => $body) {
            $this->post($body);
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame('linked_transfer_mismatch', $this->decode()['code'], $case);
        }

        $this->post(['type' => 'WITHDRAWAL', 'tradeDate' => '2026-03-03', 'grossAmount' => $this->eur('200.00'), 'linkedTransactionId' => self::LEG_OUT]);
        self::assertResponseStatusCodeSame(201);

        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($deposit['id']).'/void', ['version' => 1, 'reason' => 'Erreur']);
        self::assertResponseStatusCodeSame(200);
        $this->post(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->rows('transaction_transactions WHERE id = \''.self::LEG_IN.'\''), 'the leg itself is untouched');
    }

    public function testCorrectingAnOperationMayKeepItsOwnTransferLink(): void
    {
        $deposit = $this->record(['type' => 'DEPOSIT', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN, 'note' => 'a']);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($deposit['id']).'/correct', ['version' => 1, 'operation' => [
            'type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN, 'note' => 'b',
        ]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::LEG_IN, $this->decode()['linkedTransactionId']);
    }

    public function testTransferCandidatesListUnlinkedLegsOfTheRequestedDirection(): void
    {
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-transfer-candidates?direction=IN');
        self::assertResponseIsSuccessful();
        self::assertSame([[
            'transactionId' => self::LEG_IN, 'bookedOn' => '2026-03-02', 'amount' => ['value' => '500.00', 'assetCode' => 'EUR'], 'label' => 'Investissement mensuel',
        ]], $this->decode()['items']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-transfer-candidates?direction=OUT');
        self::assertSame([self::LEG_OUT], array_column(self::items($this->decode()), 'transactionId'));

        $this->record(['type' => 'DEPOSIT', 'grossAmount' => $this->eur('500.00'), 'linkedTransactionId' => self::LEG_IN]);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-transfer-candidates?direction=IN');
        self::assertSame([], $this->decode()['items']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-transfer-candidates?direction=SIDEWAYS');
        self::assertResponseStatusCodeSame(422);
        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-transfer-candidates?direction=IN');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::CURRENT_ACCOUNT.'/portfolio-transfer-candidates?direction=IN');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAClosedMonthRefusesCreateVoidAndCorrectAndAnyDateMovingOutOfIt(): void
    {
        $march = $this->record($this->buy());
        $april = $this->record($this->buy(['tradeDate' => '2026-04-10']));
        $this->closeMonthInDatabase($this->connection, 2026, 3);
        $operations = $this->rows('portfolio_operations');

        $this->post($this->buy(['tradeDate' => '2026-03-20']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($march['id']).'/void', ['version' => 1, 'reason' => 'Erreur']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($march['id']).'/correct', ['version' => 1, 'operation' => $this->buy(['tradeDate' => '2026-04-02'])]);
        self::assertResponseStatusCodeSame(409, 'moving a date out of a closed month');
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($april['id']).'/correct', ['version' => 1, 'operation' => $this->buy(['tradeDate' => '2026-03-02'])]);
        self::assertResponseStatusCodeSame(409, 'moving a date into a closed month');
        self::assertSame('/problems/period-closed', $this->decode()['type']);

        self::assertSame($operations, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows("portfolio_operations WHERE state = 'VOIDED'"));
        $this->get('/api/v1/portfolio-operations/'.self::string($march['id']));
        self::assertResponseIsSuccessful();
        $this->get($this->path());
        self::assertResponseIsSuccessful();
    }

    public function testAnotherWorkspacesOperationIsInvisibleToReadVoidAndCorrect(): void
    {
        $foreign = '00000000-0000-7000-8000-000000000a99';
        $this->insertForeignOperation($foreign);

        $answers = [];
        foreach ([$foreign, self::UNKNOWN] as $id) {
            $this->get('/api/v1/portfolio-operations/'.$id);
            self::assertResponseStatusCodeSame(404);
            $read = (string) $this->client->getResponse()->getContent();
            $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 1, 'reason' => 'x']);
            self::assertResponseStatusCodeSame(404);
            $void = (string) $this->client->getResponse()->getContent();
            $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 1, 'operation' => $this->buy()]);
            self::assertResponseStatusCodeSame(404);
            $answers[] = [$read, $void, (string) $this->client->getResponse()->getContent()];
        }
        self::assertSame($answers[0], $answers[1]);
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT state FROM portfolio_operations WHERE id = ?', [$foreign]));
        $this->get($this->path());
        self::assertSame([], $this->decode()['items']);
    }

    public function testTheListFiltersOrdersAndPaginates(): void
    {
        $first = $this->record($this->buy(['tradeDate' => '2026-03-02']));
        $second = $this->record($this->buy(['tradeDate' => '2026-03-02', 'note' => 'second']));
        $deposit = $this->record(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-10', 'grossAmount' => $this->eur('50')]);
        $stock = $this->record(['type' => 'SECURITY_TRANSFER_IN', 'tradeDate' => '2026-04-01', 'instrumentId' => self::STOCK, 'quantity' => '2']);

        $this->get($this->path());
        $page = $this->decode();
        self::assertSame([$stock['id'], $deposit['id'], $second['id'], $first['id']], array_column(self::items($page), 'id'));
        self::assertSame(1, $page['page']);
        self::assertSame(50, $page['perPage']);
        self::assertSame(4, $page['total']);
        $itemsById = array_column(self::items($page), null, 'id');
        self::assertSame(2, $itemsById[self::string($first['id'])]['activeDayOperationCount']);
        self::assertSame(2, $itemsById[self::string($second['id'])]['activeDayOperationCount']);
        self::assertSame(1, $itemsById[self::string($deposit['id'])]['activeDayOperationCount']);
        self::assertSame(1, $itemsById[self::string($stock['id'])]['activeDayOperationCount']);

        $this->get($this->path('?perPage=2&page=2'));
        self::assertSame([$second['id'], $first['id']], array_column(self::items($this->decode()), 'id'));
        $this->get($this->path('?type=BUY&type=SECURITY_TRANSFER_IN'));
        self::assertSame(3, $this->decode()['total']);
        $this->get($this->path('?instrumentId='.self::STOCK));
        self::assertSame([$stock['id']], array_column(self::items($this->decode()), 'id'));
        $this->get($this->path('?from=2026-03-05&to=2026-03-31'));
        self::assertSame([$deposit['id']], array_column(self::items($this->decode()), 'id'));

        foreach (['?page=0', '?perPage=101', '?perPage=abc', '?type=NOPE', '?from=2026-13-01', '?from=2010-01-01&to=2026-01-01', '?instrumentId=x', '?includeVoided=maybe'] as $query) {
            $this->get($this->path($query));
            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame('invalid_operation_query', $this->decode()['code']);
        }
    }

    public function testTheAuditTrailNamesTheEventsWithoutAnyFigure(): void
    {
        $buy = $this->record($this->buy(['reference' => 'ORDRE-SECRET-42', 'note' => 'note secrète']));
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($buy['id']).'/correct', ['version' => 1, 'reason' => 'raison secrète', 'operation' => $this->buy(['quantity' => '11', 'grossAmount' => $this->eur('2536.26')])]);
        $replacement = $this->decode();
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($replacement['id']).'/void', ['version' => 1, 'reason' => 'raison secrète']);

        self::assertSame(
            ['portfolio_operation.recorded', 'portfolio_operation.recorded', 'portfolio_operation.replaced', 'portfolio_operation.voided'],
            $this->connection->fetchFirstColumn("SELECT event_type FROM audit_events WHERE workspace_id = ? AND entity_type = 'portfolio_operation' ORDER BY occurred_at, id", [WorkspaceFixture::OWN_WORKSPACE]),
        );
        $trail = implode(' ', array_map(static fn (mixed $row): string => is_string($row) ? $row : '', $this->connection->fetchFirstColumn("SELECT before_json::text || after_json::text FROM audit_events WHERE entity_type = 'portfolio_operation'")));
        foreach (['2305.69', '230.5688', '1.99', 'SECRET', 'secrète', 'secret', '2307.68', '2536.26'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $trail);
        }
        self::assertStringContainsString('BUY', $trail);
        self::assertStringContainsString(self::ETF, $trail);
    }

    public function testTheActiveOperationLimitIsEnforcedPerAccount(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO portfolio_operations (id, workspace_id, account_id, operation_type, trade_date, sequence, asset_code,
                gross_amount_value, gross_amount_scale, fees_value, fees_scale, taxes_value, taxes_scale, cash_amount_value, cash_amount_scale,
                state, created_at, created_by, version)
            SELECT ('00000000-0000-7000-8000-' || lpad(to_hex(n), 12, '0'))::uuid, ?::uuid, ?::uuid, 'DEPOSIT', DATE '2026-03-01', n, 'EUR',
                1, 0, 0, 0, 0, 0, 1, 0, 'ACTIVE', now(), ?::uuid, 1
            FROM generate_series(1, 10000) AS n
            SQL, [WorkspaceFixture::OWN_WORKSPACE, self::ACCOUNT, WorkspaceFixture::OWNER_ID]);

        $this->post(['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $this->eur('5')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('operation_limit_reached', $this->decode()['code']);
        self::assertSame(10000, $this->rows('portfolio_operations'));
    }

    public function testMutationsRequireTheCsrfToken(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');
        $this->post($this->buy());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    public function testPositionsAndLotsExposeTheReferenceReplayAtCurrentAndPastDates(): void
    {
        $this->record($this->buy(['tradeDate' => '2026-03-01', 'quantity' => '10', 'unitPrice' => '100', 'grossAmount' => $this->eur('1000.00'), 'fees' => $this->eur('2.00')]));
        $second = $this->record($this->buy(['tradeDate' => '2026-03-02', 'quantity' => '5', 'unitPrice' => '120', 'grossAmount' => $this->eur('600.00'), 'fees' => $this->eur('1.00')]));
        $this->record([
            'type' => 'SELL', 'tradeDate' => '2026-03-03', 'instrumentId' => self::ETF, 'quantity' => '12', 'unitPrice' => '100',
            'grossAmount' => $this->eur('1200.00'),
        ]);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions');
        self::assertResponseIsSuccessful();
        $positions = $this->decode();
        self::assertIsArray($positions['items']);
        self::assertIsArray($positions['items'][0]);
        /** @var array<string, mixed> $position */
        $position = $positions['items'][0];
        self::assertSame('3', $position['quantity']);
        self::assertSame('360.600000000000000000000000', self::amountValue($position, 'fifoCostBasis'));
        self::assertSame('320.600000000000000000000000', self::amountValue($position, 'averageCostBasis'));
        self::assertSame('106.866666666666666666666667', self::amountValue($position, 'averageUnitCost'));
        self::assertSame(1, $position['openLotCount']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions?asOf=2026-03-02');
        $historical = $this->decode();
        self::assertIsArray($historical['items']);
        self::assertIsArray($historical['items'][0]);
        self::assertSame('15', $historical['items'][0]['quantity']);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions/'.self::ETF.'/lots');
        $lots = $this->decode();
        self::assertIsArray($lots['openLots']);
        self::assertIsArray($lots['openLots'][0]);
        self::assertIsArray($lots['movements']);
        self::assertIsString($second['id']);
        self::assertSame($second['id'].'#0', $lots['openLots'][0]['lotId']);
        self::assertSame(4, count($lots['movements']));
    }

    public function testDerivedPositionTotalsMayExceedTheStoredDecimalWidth(): void
    {
        $maximum = '99999999999999999999999999.00';
        $buy = $this->buy([
            'quantity' => '1',
            'unitPrice' => $maximum,
            'grossAmount' => $this->eur($maximum),
            'fees' => $this->eur('0'),
        ]);
        $this->record($buy);
        $this->record($buy);

        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions');
        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertIsArray($body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('2', $body['items'][0]['quantity']);
        self::assertIsArray($body['items'][0]['averageCostBasis']);
        self::assertSame('199999999999999999999999998.00', $body['items'][0]['averageCostBasis']['value']);
    }

    public function testAWriteThatWouldInvalidateHistoryRollsBackCreateVoidAndCorrection(): void
    {
        $this->post([
            'type' => 'SELL', 'tradeDate' => '2026-03-01', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '10',
            'grossAmount' => $this->eur('10'),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('position_would_go_negative', $this->decode()['code']);
        self::assertSame(0, $this->rows('portfolio_operations'));

        $buy = $this->record($this->buy(['quantity' => '4', 'unitPrice' => '10', 'grossAmount' => $this->eur('40'), 'fees' => $this->eur('0')]));
        $this->record([
            'type' => 'SELL', 'tradeDate' => '2026-03-03', 'instrumentId' => self::ETF, 'quantity' => '4', 'unitPrice' => '10',
            'grossAmount' => $this->eur('40'),
        ]);

        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($buy['id']).'/void', ['version' => 1, 'reason' => 'test']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT state FROM portfolio_operations WHERE id = ?', [$buy['id']]));

        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($buy['id']).'/correct', [
            'version' => 1,
            'reason' => 'test',
            'operation' => $this->buy(['quantity' => '3', 'unitPrice' => '10', 'grossAmount' => $this->eur('30'), 'fees' => $this->eur('0')]),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, $this->rows('portfolio_operations'));
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT state FROM portfolio_operations WHERE id = ?', [$buy['id']]));
    }

    public function testReorderRequiresTheExactDaySetAndRollsBackAnInvalidHistory(): void
    {
        $buy = $this->record($this->buy(['tradeDate' => '2026-03-02', 'quantity' => '1', 'unitPrice' => '10', 'grossAmount' => $this->eur('10'), 'fees' => $this->eur('0')]));
        $sell = $this->record([
            'type' => 'SELL', 'tradeDate' => '2026-03-02', 'instrumentId' => self::ETF, 'quantity' => '1', 'unitPrice' => '10',
            'grossAmount' => $this->eur('10'),
        ]);

        $this->send('PUT', $this->path('/order'), ['tradeDate' => '2026-03-02', 'operationIds' => [$buy['id']]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_operation_order', $this->decode()['code']);

        $this->send('PUT', $this->path('/order'), ['tradeDate' => '2026-03-02', 'operationIds' => [$sell['id'], $buy['id']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('position_would_go_negative', $this->decode()['code']);
        self::assertSame([$buy['id'], $sell['id']], $this->connection->fetchFirstColumn(
            'SELECT id FROM portfolio_operations WHERE account_id = ? ORDER BY sequence', [self::ACCOUNT],
        ));

        $this->send('PUT', $this->path('/order'), ['tradeDate' => '2026-03-02', 'operationIds' => [$buy['id'], $sell['id']]]);
        self::assertResponseIsSuccessful();
        self::assertSame([$buy['id'], $sell['id']], $this->decode()['operationIds']);
        self::assertSame('portfolio_operation.reordered', $this->connection->fetchOne("SELECT event_type FROM audit_events WHERE event_type = 'portfolio_operation.reordered'"));
    }

    public function testReorderAcceptsTheBoundedTenThousandIdentifierRequestSize(): void
    {
        $ids = [];
        for ($index = 1; $index <= 10_000; ++$index) {
            $ids[] = sprintf('00000000-0000-7000-8000-%012x', $index);
        }

        $this->send('PUT', $this->path('/order'), ['tradeDate' => '2026-03-02', 'operationIds' => $ids]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_operation_order', $this->decode()['code']);
    }

    public function testPositionReadsAreWorkspaceScopedAndRejectFutureDates(): void
    {
        $this->get('/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-positions');
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions/'.self::FOREIGN_INSTRUMENT.'/lots');
        self::assertResponseStatusCodeSame(404);
        $this->send('PUT', '/api/v1/accounts/'.self::FOREIGN_ACCOUNT.'/portfolio-operations/order', [
            'tradeDate' => '2026-03-02',
            'operationIds' => ['00000000-0000-7000-8000-000000000001'],
        ]);
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/v1/accounts/'.self::ACCOUNT.'/portfolio-positions?asOf=2099-01-01');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_position_query', $this->decode()['code']);
    }

    public function testAnUsedPortfolioAccountCannotLeavePortfolioValuationMode(): void
    {
        $this->record($this->buy());
        $version = $this->connection->fetchOne('SELECT version FROM account_financial_accounts WHERE id = ?', [self::ACCOUNT]);
        $body = [
            'label' => 'Compte-titres', 'kind' => 'PORTFOLIO', 'productCode' => null, 'productModelId' => null, 'institution' => null,
            'maskedIdentifier' => null, 'valuationMode' => 'PORTFOLIO', 'liquidityLevel' => 'IMMEDIATE', 'includeInNetWorth' => false,
            'includeInEmergencyFund' => false, 'openedOn' => '2026-01-01', 'closedOn' => null, 'version' => is_numeric($version) ? (int) $version : 0,
            'primaryGroupId' => null, 'tagGroupIds' => [],
        ];

        $this->send('PUT', '/api/v1/accounts/'.self::ACCOUNT, [...$body, 'valuationMode' => 'SNAPSHOTS']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('PORTFOLIO', $this->connection->fetchOne('SELECT valuation_mode FROM account_financial_accounts WHERE id = ?', [self::ACCOUNT]));

        $this->send('PUT', '/api/v1/accounts/'.self::ACCOUNT, [...$body, 'label' => 'Compte-titres renommé']);
        self::assertResponseStatusCodeSame(200);
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
            'grossAmount' => $this->eur('2305.69'), 'fees' => $this->eur('1.99'), 'reference' => 'ORD-1',
            ...$overrides,
        ];
    }

    /** @return array{value: string, assetCode: string} */
    private function eur(string $value): array
    {
        return ['value' => $value, 'assetCode' => 'EUR'];
    }

    private function trimDecimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function path(string $suffix = ''): string
    {
        return '/api/v1/accounts/'.self::ACCOUNT.'/portfolio-operations'.$suffix;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function record(array $body): array
    {
        $this->post(['tradeDate' => '2026-03-02', ...$body]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, ?string $idempotencyKey = null): void
    {
        $this->send('POST', $this->path(), $body, null === $idempotencyKey ? [] : ['HTTP_IDEMPOTENCY_KEY' => $idempotencyKey]);
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $uri, array $body, array $headers = []): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function rows(string $tableAndWhere): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM '.$tableAndWhere);
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function insertForeignOperation(string $id): void
    {
        $this->connection->insert('portfolio_operations', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE, 'account_id' => self::FOREIGN_ACCOUNT, 'operation_type' => 'DEPOSIT',
            'trade_date' => '2026-03-02', 'sequence' => 1, 'asset_code' => 'EUR', 'gross_amount_value' => '5', 'gross_amount_scale' => 0,
            'fees_value' => '0', 'fees_scale' => 0, 'taxes_value' => '0', 'taxes_scale' => 0, 'cash_amount_value' => '5', 'cash_amount_scale' => 0,
            'state' => 'ACTIVE', 'created_at' => '2026-03-02 10:00:00+00', 'created_by' => WorkspaceFixture::OTHER_OWNER_ID, 'version' => 1,
        ]);
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

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $page): array
    {
        $items = $page['items'] ?? null;
        self::assertIsList($items);

        return array_map(static function (mixed $item): array {
            self::assertIsArray($item);
            $typed = [];
            foreach ($item as $key => $value) {
                self::assertIsString($key);
                $typed[$key] = $value;
            }

            return $typed;
        }, $items);
    }

    /** @param array<string, mixed> $operation */
    private static function amountValue(array $operation, string $field): string
    {
        $amount = $operation[$field] ?? null;
        self::assertIsArray($amount);
        self::assertIsString($amount['value'] ?? null);

        return $amount['value'];
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}

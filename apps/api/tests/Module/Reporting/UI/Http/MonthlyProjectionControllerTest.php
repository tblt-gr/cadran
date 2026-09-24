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

final class MonthlyProjectionControllerTest extends WebTestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000a1';
    private const string SAVINGS = '00000000-0000-7000-8000-0000000000a2';
    private const string PORTFOLIO = '00000000-0000-7000-8000-0000000000a3';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000a9';
    private const string OTHER_SAVINGS = '00000000-0000-7000-8000-0000000000a8';
    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    /** The last closed month, so the projection never depends on the day the suite runs. */
    private string $month;
    private \DateTimeImmutable $periodStart;
    private \DateTimeImmutable $periodEnd;
    /** The last calendar day of the month preceding {@see $month}: the compared day. */
    private \DateTimeImmutable $previousAsOf;
    private \DateTimeImmutable $bookedOn;
    private string $runningMonth;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($this->connection);
        $this->fixture->reset();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());

        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $today = new \DateTimeImmutable($paris->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->periodStart = $today->modify('first day of last month');
        $this->periodEnd = $this->periodStart->modify('last day of this month');
        $this->previousAsOf = $this->periodStart->modify('-1 day');
        $this->bookedOn = $this->periodStart->modify('+14 days');
        $this->month = $this->periodStart->format('Y-m');
        $this->runningMonth = $today->format('Y-m');
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheMonthlyProjectionAppliesEveryPolicyAndIsolatesTheWorkspace(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Épargne', 'SAVINGS');
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->category(self::CATEGORY, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '1000.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '4900.00', 'RECONCILED');
        $this->snapshot(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '850.00', 'RECONCILED');
        $this->snapshot(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, $this->periodEnd->format('Y-m-d'), '999999.00', 'RECONCILED');

        $this->transaction('01', self::ACCOUNT, '5000.00', 'INCOME');
        $this->transaction('02', self::ACCOUNT, '-1200.00', 'EXPENSE', splitAmount: '-1200.00');
        $this->transaction('03', self::ACCOUNT, '-200.00', 'EXPENSE');
        $this->transaction('04', self::ACCOUNT, '100.00', 'REFUND', splitAmount: '100.00');
        $this->transaction('05', self::SAVINGS, '750.00', 'TRANSFER');
        $this->transaction('06', self::ACCOUNT, '20.00', 'ADJUSTMENT');
        $this->transaction('07', self::ACCOUNT, '-999.00', 'EXPENSE', state: 'PENDING');
        $this->transaction('09', self::OTHER_ACCOUNT, '999999.00', 'INCOME', workspace: WorkspaceFixture::OTHER_WORKSPACE);

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('PENDING', $report['state']);
        self::assertSame(1, $report['pendingCount']);
        self::assertSame('5000.00', self::metric($report, 'cashIncome'));
        self::assertSame('1300.00', self::metric($report, 'budgetExpenses'));
        self::assertSame('200.00', self::metric($report, 'uncategorizedExpenses'));
        self::assertSame('3700.00', self::metric($report, 'budgetSurplus'));
        self::assertSame('750.00', self::metric($report, 'savingsTransfers'));
        self::assertSame('0.740000000000000000000000', self::metric($report, 'cashSavingsRate'));
        self::assertNull(self::fields($report['nonCashBenefits'])['value']);
        self::assertSame('MISSING_BENEFIT_SOURCE', self::fields($report['nonCashBenefits'])['reason']);
        self::assertSame('4650.00', self::metric($report, 'netWorthDelta'));
        self::assertSame('POSITIVE', $report['beginningNetWorthState']);
        self::assertSame('RECONCILED', $report['reconciliationStatus']);
        self::assertStringNotContainsString('999999.00', (string) $this->client->getResponse()->getContent());

        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $other = $this->read('/api/v1/reports/monthly?month='.$this->month);
        self::assertSame('999999.00', self::metric($other, 'cashIncome'));
        self::assertStringNotContainsString('5000.00', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyZeroIncomeAndMalformedMonthsAreExplicit(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '0', 'UNRECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '0', 'UNRECONCILED');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);
        self::assertSame('EMPTY', $report['state']);
        self::assertSame('0', self::metric($report, 'cashIncome'));
        self::assertNull(self::fields($report['cashSavingsRate'])['value']);
        self::assertSame('ZERO_CASH_INCOME', self::fields($report['cashSavingsRate'])['reason']);
        self::assertSame('0', self::metric($report, 'netSavingsTransfers'));
        self::assertNull(self::fields($report['netSavingsRate'])['value']);
        self::assertSame('ZERO_CASH_INCOME', self::fields($report['netSavingsRate'])['reason']);
        self::assertSame('ZERO', $report['beginningNetWorthState']);

        foreach (['', '2026-00', '2026-13', '1899-12', '3000-01', '09-2026'] as $month) {
            $this->client->request('GET', '/api/v1/reports/monthly?month='.$month);
            self::assertResponseStatusCodeSame(400);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
        }
    }

    public function testNetSavingsCountsPairsOnceAndExposesTransferProvenance(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Épargne', 'SAVINGS');
        $this->account(self::PORTFOLIO, WorkspaceFixture::OWN_WORKSPACE, 'PEA', 'PORTFOLIO');
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret courant', 'CURRENT');
        $this->account(self::OTHER_SAVINGS, WorkspaceFixture::OTHER_WORKSPACE, 'Secret épargne', 'SAVINGS');
        $this->transaction('01', self::ACCOUNT, '1000.00', 'INCOME');
        $this->transferPair('a', self::ACCOUNT, self::SAVINGS, '300.00');
        $this->transferPair('b', self::SAVINGS, self::ACCOUNT, '50.00');
        $this->transferPair('c', self::SAVINGS, self::PORTFOLIO, '100.00');
        $this->transferPair('d', self::OTHER_ACCOUNT, self::OTHER_SAVINGS, '999999.00', WorkspaceFixture::OTHER_WORKSPACE);

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('400.00', self::metric($report, 'savingsTransfers'));
        self::assertSame('300.00', self::metric($report, 'savingsInflows'));
        self::assertSame('50.00', self::metric($report, 'savingsWithdrawals'));
        self::assertSame('250.00', self::metric($report, 'netSavingsTransfers'));
        self::assertSame('0.250000000000000000000000', self::metric($report, 'netSavingsRate'));
        self::assertStringNotContainsString('999999', (string) $this->client->getResponse()->getContent());

        $detail = $this->read('/api/v1/reports/monthly/netSavingsTransfers/explain?month='.$this->month);
        self::assertSame('net savings transfers = savings inflows - savings withdrawals', $detail['formula']);
        self::assertSame([
            '00000000-0000-7000-8000-0000000004a3',
            '00000000-0000-7000-8000-0000000004b3',
        ], $detail['sourceTransferIds']);
        self::assertSame([
            '00000000-0000-7000-8000-0000000001a1',
            '00000000-0000-7000-8000-0000000001a2',
            '00000000-0000-7000-8000-0000000001b1',
            '00000000-0000-7000-8000-0000000001b2',
        ], $detail['sourceTransactionIds']);
        $sourceTransactions = self::fields($detail)['sourceTransactions'];
        self::assertIsArray($sourceTransactions);
        self::assertCount(4, $sourceTransactions);
    }

    public function testMixedAssetsMissingAndStaleValuationsStayExplicit(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Dollar', 'SAVINGS', 'USD');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodStart->modify('-11 days')->format('Y-m-d'), '-100.00', 'UNRECONCILED');
        $this->transaction('01', self::ACCOUNT, '5000.00', 'INCOME');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('MISSING', $report['quality']);
        self::assertSame('NON_CALCULABLE', $report['beginningNetWorthState']);
        self::assertNull(self::fields($report['cashIncome'])['value']);
        self::assertSame('MIXED_ASSETS', self::fields($report['cashIncome'])['reason']);
        self::assertNull(self::fields($report['netWorthDelta'])['value']);
        self::assertSame('MISSING_VALUATION', self::fields($report['netWorthDelta'])['reason']);
        $accounts = $report['accounts'];
        self::assertIsArray($accounts);
        self::assertSame('STALE', self::fields(self::fields($accounts[0])['endValue'])['quality']);
        self::assertSame('MISSING', self::fields(self::fields($accounts[1])['endValue'])['quality']);

        $cash = $this->read('/api/v1/reports/monthly/cashIncome/explain?month='.$this->month);
        self::assertNull($cash['value']);
        self::assertSame('MIXED_ASSETS', $cash['reason']);
        self::assertSame(['00000000-0000-7000-8000-000000000101'], $cash['sourceTransactionIds']);
        $endNetWorth = $this->read('/api/v1/reports/monthly/endNetWorth/explain?month='.$this->month);
        self::assertSame([self::ACCOUNT, self::SAVINGS], $endNetWorth['sourceAccountIds']);
    }

    public function testNegativePriorWealthKeepsAnExactDeltaAndNamesTheBaseState(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Dette nette', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '-100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '-50.00', 'RECONCILED');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('NEGATIVE', $report['beginningNetWorthState']);
        self::assertSame('50.00', self::metric($report, 'netWorthDelta'));
    }

    public function testTheBeginningNetWorthIsThePrecedingMonthEndAndNotTheFirstDayOfTheMonth(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '1000.00', 'RECONCILED');
        // The first day of the month already carries that month's opening
        // movements: reading it as the beginning would hide them.
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodStart->format('Y-m-d'), '4242.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '1500.00', 'RECONCILED');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('1000.00', self::metric($report, 'beginningNetWorth'));
        self::assertSame('1500.00', self::metric($report, 'endNetWorth'));
        self::assertSame('500.00', self::metric($report, 'netWorthDelta'));
    }

    public function testQualityUsesTheSamePrecedingMonthEndValuationAsBeginningNetWorth(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->modify('-2 days')->format('Y-m-d'), '1000.00', 'RECONCILED');
        // A first-of-month value is current for the old account-facts envelope,
        // but it is not the source of the published N−1 net-worth figure.
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodStart->format('Y-m-d'), '1100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '1500.00', 'RECONCILED');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('1000.00', self::metric($report, 'beginningNetWorth'));
        self::assertSame('STALE', $report['quality']);
    }

    public function testTheRunningMonthStopsOnTodayAndNeverReadsAFutureDatedValuation(): void
    {
        $this->signIn();
        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $today = new \DateTimeImmutable($paris->format('Y-m-d'), new \DateTimeZone('UTC'));
        $monthEnd = $today->modify('last day of this month');
        if ($today->format('Y-m-d') === $monthEnd->format('Y-m-d')) {
            self::markTestSkipped('The running month is complete today; its provisional end cannot be observed.');
        }
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $today->modify('first day of this month')->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $monthEnd->format('Y-m-d'), '777777.00', 'RECONCILED');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->runningMonth);

        self::assertSame('100.00', self::metric($report, 'endNetWorth'));
        self::assertStringNotContainsString('777777', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAccountArchivedAfterParisMidnightKeepsItsClosedMonthHistoryOnly(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Ancien compte', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '150.00', 'RECONCILED');
        $this->transaction('01', self::ACCOUNT, '50.00', 'INCOME');
        // The fixture workspace is in Europe/Paris: this instant is half past
        // midnight on the first day of the next month there, while the UTC
        // clock still reads the last day of the closed month.
        $archivedAt = (new \DateTimeImmutable(
            $this->periodEnd->modify('+1 day')->format('Y-m-d').' 00:30:00',
            new \DateTimeZone('Europe/Paris'),
        ))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        self::assertSame($this->periodEnd->format('Y-m-d'), substr($archivedAt, 0, 10));
        $this->connection->update('account_financial_accounts', [
            'archived_at' => $archivedAt,
            'updated_at' => $archivedAt,
            'version' => 2,
        ], ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::ACCOUNT]);

        $closed = $this->read('/api/v1/reports/monthly?month='.$this->month);
        self::assertSame('50.00', self::metric($closed, 'cashIncome'));
        self::assertSame('150.00', self::metric($closed, 'endNetWorth'));
        $closedAccounts = $closed['accounts'];
        self::assertIsArray($closedAccounts);
        self::assertCount(1, $closedAccounts);

        $closedNetWorth = $this->read('/api/v1/net-worth?asOf='.$this->periodEnd->format('Y-m-d'));
        self::assertSame('150.00', self::fields($closedNetWorth['total'])['value']);

        $running = $this->read('/api/v1/reports/monthly?month='.$this->runningMonth);
        self::assertNull(self::fields($running['cashIncome'])['value']);
        self::assertSame('NO_ACCOUNT', self::fields($running['cashIncome'])['reason']);
        self::assertNull(self::fields($running['endNetWorth'])['value']);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', self::fields($running['endNetWorth'])['reason']);
        self::assertSame([], $running['accounts']);

        $runningNetWorth = $this->read('/api/v1/net-worth?asOf='.$this->periodEnd->modify('+1 day')->format('Y-m-d'));
        self::assertNull($runningNetWorth['total']);
        self::assertSame('NO_ELIGIBLE_ACCOUNT', $runningNetWorth['reason']);
    }

    public function testBeginningAndEndNetWorthKeepTheirOwnAsymmetricReasons(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Euro', 'CURRENT');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Dollar', 'SAVINGS', 'USD', $this->periodStart->modify('+1 day')->format('Y-m-d'));
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '100.00', 'RECONCILED', 'USD');

        $report = $this->read('/api/v1/reports/monthly?month='.$this->month);

        self::assertSame('100.00', self::fields($report['beginningNetWorth'])['value']);
        self::assertNull(self::fields($report['beginningNetWorth'])['reason']);
        self::assertNull(self::fields($report['endNetWorth'])['value']);
        self::assertSame('MIXED_ASSETS', self::fields($report['endNetWorth'])['reason']);
        self::assertNull(self::fields($report['netWorthDelta'])['value']);
        self::assertSame('MIXED_ASSETS', self::fields($report['netWorthDelta'])['reason']);

        $beginning = $this->read('/api/v1/reports/monthly/beginningNetWorth/explain?month='.$this->month);
        self::assertSame([self::ACCOUNT], $beginning['sourceAccountIds']);
        $end = $this->read('/api/v1/reports/monthly/endNetWorth/explain?month='.$this->month);
        self::assertSame([self::ACCOUNT, self::SAVINGS], $end['sourceAccountIds']);
        $delta = $this->read('/api/v1/reports/monthly/netWorthDelta/explain?month='.$this->month);
        self::assertSame([self::ACCOUNT, self::SAVINGS], $delta['sourceAccountIds']);
    }

    public function testItExplainsTheExactMonthlyViewWithWorkspaceScopedSources(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Épargne', 'SAVINGS');
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->category(self::CATEGORY, WorkspaceFixture::OWN_WORKSPACE, true);
        // Both the compared day and the period start carry a valuation, so the
        // published quality is about the explain envelope rather than about a
        // figure that is one day old at the start of the month.
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodStart->format('Y-m-d'), '100.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '150.00', 'RECONCILED');
        $this->snapshot(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->format('Y-m-d'), '10.00', 'RECONCILED');
        $this->snapshot(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, $this->periodStart->format('Y-m-d'), '10.00', 'RECONCILED');
        $this->snapshot(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, $this->periodEnd->format('Y-m-d'), '30.00', 'RECONCILED');
        $this->transaction('01', self::ACCOUNT, '50.00', 'INCOME');
        $this->transaction('02', self::ACCOUNT, '5.00', 'INCOME', state: 'PENDING');
        $this->transaction('03', self::ACCOUNT, '-10.00', 'EXPENSE', state: 'PENDING', splitAmount: '-10.00');
        $this->transaction('04', self::SAVINGS, '20.00', 'TRANSFER', state: 'PENDING');
        $this->transaction('05', self::ACCOUNT, '99.00', 'ADJUSTMENT', state: 'PENDING');
        $this->transaction('09', self::OTHER_ACCOUNT, '999999.00', 'INCOME', workspace: WorkspaceFixture::OTHER_WORKSPACE);

        $detail = $this->read('/api/v1/reports/monthly/cashIncome/explain?month='.$this->month);

        self::assertSame('cashIncome', $detail['kpi']);
        self::assertSame('50.00', $detail['value']);
        self::assertSame('EUR', $detail['assetCode']);
        self::assertNull($detail['reason']);
        self::assertNull($detail['reasonExplanation']);
        self::assertIsString($detail['formula']);
        self::assertIsString($detail['scope']);
        self::assertSame(['start' => $this->periodStart->format('Y-m-d'), 'end' => $this->periodEnd->format('Y-m-d')], $detail['period']);
        self::assertSame(['00000000-0000-7000-8000-000000000101'], $detail['sourceTransactionIds']);
        self::assertSame([[
            'id' => '00000000-0000-7000-8000-000000000101',
            'bookedOn' => $this->bookedOn->format('Y-m-d'),
            'label' => 'Projection 01',
            'amount' => ['value' => '50.00', 'assetCode' => 'EUR'],
            'state' => 'BOOKED',
        ]], $detail['sourceTransactions']);
        self::assertSame([], $detail['sourceAccountIds']);
        self::assertSame('PENDING', $detail['freshness']);
        self::assertSame('CURRENT', $detail['quality']);
        self::assertSame(1, $detail['pendingCount']);
        self::assertArrayNotHasKey('policyVersion', $detail);
        self::assertArrayNotHasKey('counterparty', self::fields($detail['sourceTransactions'][0]));
        self::assertArrayNotHasKey('note', self::fields($detail['sourceTransactions'][0]));
        self::assertArrayNotHasKey('bankReference', self::fields($detail['sourceTransactions'][0]));
        self::assertStringNotContainsString('999999', (string) $this->client->getResponse()->getContent());

        $expenses = $this->read('/api/v1/reports/monthly/budgetExpenses/explain?month='.$this->month);
        self::assertSame(1, $expenses['pendingCount']);
        self::assertSame('PENDING', $expenses['freshness']);
        $uncategorized = $this->read('/api/v1/reports/monthly/uncategorizedExpenses/explain?month='.$this->month);
        self::assertSame(0, $uncategorized['pendingCount']);
        self::assertSame('CURRENT', $uncategorized['freshness']);
        $surplus = $this->read('/api/v1/reports/monthly/budgetSurplus/explain?month='.$this->month);
        self::assertSame(2, $surplus['pendingCount']);
        self::assertSame('PENDING', $surplus['freshness']);
        $savings = $this->read('/api/v1/reports/monthly/savingsTransfers/explain?month='.$this->month);
        self::assertSame(1, $savings['pendingCount']);
        self::assertSame('PENDING', $savings['freshness']);
        $rate = $this->read('/api/v1/reports/monthly/cashSavingsRate/explain?month='.$this->month);
        self::assertSame(2, $rate['pendingCount']);
        self::assertSame('PENDING', $rate['freshness']);

        $missing = $this->read('/api/v1/reports/monthly/nonCashBenefits/explain?month='.$this->month);
        self::assertNull($missing['value']);
        self::assertSame('MISSING_BENEFIT_SOURCE', $missing['reason']);
        self::assertIsString($missing['reasonExplanation']);
        self::assertSame(0, $missing['pendingCount']);
        self::assertSame('CURRENT', $missing['freshness']);
        self::assertSame([], $missing['sourceTransactions']);

        $this->client->request('GET', '/api/v1/reports/monthly/not-a-kpi/explain?month='.$this->month);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnAnonymousCallerReadsNothing(): void
    {
        $this->client->request('GET', '/api/v1/reports/monthly?month='.$this->month);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/reports/monthly/cashIncome/explain?month='.$this->month);
        self::assertResponseStatusCodeSame(401);
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function read(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return self::fields($data);
    }

    private function account(
        string $id,
        string $workspace,
        string $label,
        string $kind,
        string $asset = 'EUR',
        ?string $openedOn = null,
    ): void {
        $openedOn ??= $this->previousAsOf->modify('-1 year')->format('Y-m-d');
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => $asset, 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => $openedOn, 'version' => 1,
            'created_at' => $openedOn.' 12:00:00+00', 'updated_at' => $openedOn.' 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function category(string $id, string $workspace, bool $included): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => 'EXPENSE', 'label' => 'Budget',
            'default_analytic_axes' => '[]', 'budget_included' => $included, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    private function snapshot(
        string $account,
        string $workspace,
        string $day,
        string $amount,
        string $status,
        string $asset = 'EUR',
    ): void {
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($workspace.$account.$day), 0, 8).'-0000-7000-8000-000000000000',
            'workspace_id' => $workspace, 'account_id' => $account, 'as_of' => $day, 'amount_value' => $amount,
            'amount_literal' => $amount, 'amount_asset' => $asset, 'source' => 'MANUAL', 'reconciliation_status' => $status,
            'version' => 1, 'recorded_at' => $day.' 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function transaction(
        string $suffix,
        string $account,
        string $amount,
        string $nature,
        string $state = 'BOOKED',
        ?string $splitAmount = null,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        ?string $bookedOn = null,
        string $asset = 'EUR',
    ): void {
        $bookedOn ??= $this->bookedOn->format('Y-m-d');
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => $asset,
            'amount_value' => $amount, 'amount_scale' => 2, 'state' => $state, 'nature' => $nature,
            'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'Projection '.$suffix,
            'version' => 1, 'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
        ]);
        if (null !== $splitAmount) {
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000002'.$suffix, 'workspace_id' => $workspace,
                'transaction_id' => $id, 'category_id' => self::CATEGORY, 'amount_value' => $splitAmount,
                'amount_scale' => 2, 'asset_code' => 'EUR', 'created_at' => $bookedOn.' 12:00:00+00',
            ]);
        }
    }

    private function transferPair(
        string $suffix,
        string $sourceAccount,
        string $targetAccount,
        string $amount,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
    ): void {
        $this->transaction($suffix.'1', $sourceAccount, '-'.$amount, 'TRANSFER', workspace: $workspace);
        $this->transaction($suffix.'2', $targetAccount, $amount, 'TRANSFER', workspace: $workspace);
        $this->connection->insert('transaction_transfers', [
            'id' => '00000000-0000-7000-8000-0000000004'.$suffix.'3',
            'workspace_id' => $workspace,
            'source_transaction_id' => '00000000-0000-7000-8000-0000000001'.$suffix.'1',
            'target_transaction_id' => '00000000-0000-7000-8000-0000000001'.$suffix.'2',
            'version' => 1,
            'created_at' => $this->bookedOn->format('Y-m-d').' 12:00:00+00',
            'updated_at' => $this->bookedOn->format('Y-m-d').' 12:00:00+00',
        ]);
    }

    /** @param array<string, mixed> $report */
    private static function metric(array $report, string $key): string
    {
        $metric = self::fields($report[$key]);
        self::assertIsString($metric['value']);

        return $metric['value'];
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }
}

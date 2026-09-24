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

/**
 * The dates a recap is answered for depend on the day the workspace is living
 * in, so the fixture is dated relative to that day rather than pinned to a
 * calendar the suite would outlive.
 */
final class MonthlyRecapControllerTest extends WebTestCase
{
    private const string CURRENT = '00000000-0000-7000-8000-0000000000a1';
    private const string LIVRET_A = '00000000-0000-7000-8000-0000000000a2';
    private const string LDDS = '00000000-0000-7000-8000-0000000000a3';
    private const string ORPHAN = '00000000-0000-7000-8000-0000000000a4';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000a9';
    private const string USD_ACCOUNT = '00000000-0000-7000-8000-0000000000a8';
    private const string GROUP_LIVRETS = '00000000-0000-7000-8000-0000000000b1';
    private const string GROUP_COURANT = '00000000-0000-7000-8000-0000000000b2';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';
    private const string INCOME_CATEGORY = '00000000-0000-7000-8000-0000000000c2';
    private const string EMPTY_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c9';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private \DateTimeImmutable $today;
    private string $closedMonth;
    private \DateTimeImmutable $previousAsOf;
    private \DateTimeImmutable $currentAsOf;

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
        $this->today = new \DateTimeImmutable($paris->format('Y-m-d'), new \DateTimeZone('UTC'));
        $closedFirstDay = $this->today->modify('first day of last month');
        $this->closedMonth = $closedFirstDay->format('Y-m');
        $this->previousAsOf = $closedFirstDay->modify('-1 day');
        $this->currentAsOf = $closedFirstDay->modify('last day of this month');
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    /**
     * Hand-computed closed month, every figure in EUR:
     *   Compte courant  N−1 1000.00  N 1200.00   group Courant
     *   Livret A        N−1 5000.00  N 5500.00   group Livrets
     *   LDDS            N−1 2000.00  N 2300.00   group Livrets
     * N−1 total = 8000.00, N total = 9000.00, difference = 1000.00.
     * Change   = 1000.00 / 8000.00 = 12.50 %.
     * Livrets  = 5500.00 + 2300.00 = 7800.00, i.e. 7800/9000 = 86.67 % of N.
     * Courant  = 1200.00,                     i.e. 1200/9000 = 13.33 % of N.
     */
    public function testTheClosedMonthRecapIsExactAndEachAccountCountsOnceInOneGroup(): void
    {
        $this->signIn();
        $this->seedThreeAccounts();
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->snapshot(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, $this->currentAsOf, '999999.00');

        $recap = $this->read();

        self::assertSame($this->closedMonth, $recap['month']);
        self::assertSame($this->previousAsOf->format('Y-m-d'), $recap['previousAsOf']);
        self::assertSame($this->currentAsOf->format('Y-m-d'), $recap['currentAsOf']);
        self::assertFalse($recap['provisional']);
        self::assertSame('CURRENT', $recap['quality']);

        $accounts = self::index($recap['accounts'], 'accountId');
        self::assertCount(3, $accounts);
        self::assertSame('1000.00', self::fields($accounts[self::CURRENT]['previousValue'])['value']);
        self::assertSame('1200.00', self::fields($accounts[self::CURRENT]['currentValue'])['value']);
        self::assertSame('Livrets', $accounts[self::LIVRET_A]['primaryGroupLabel']);
        self::assertSame('5000.00', self::fields($accounts[self::LIVRET_A]['previousValue'])['value']);
        self::assertSame('5500.00', self::fields($accounts[self::LIVRET_A]['currentValue'])['value']);
        self::assertSame('2300.00', self::fields($accounts[self::LDDS]['currentValue'])['value']);
        self::assertSame('61.11', self::fields($accounts[self::LIVRET_A]['share'])['percentDisplay']);
        self::assertSame('25.56', self::fields($accounts[self::LDDS]['share'])['percentDisplay']);
        self::assertSame('13.33', self::fields($accounts[self::CURRENT]['share'])['percentDisplay']);

        $groups = self::index($recap['groups'], 'groupId');
        self::assertCount(2, $groups);
        self::assertSame('7800.00', self::fields($groups[self::GROUP_LIVRETS]['value'])['value']);
        self::assertSame(
            ['value' => '7800.00', 'assetCode' => 'EUR'],
            self::fields(self::fields($groups[self::GROUP_LIVRETS]['value'])['display']),
        );
        self::assertSame('86.67', self::fields($groups[self::GROUP_LIVRETS]['share'])['percentDisplay']);
        self::assertSame('1200.00', self::fields($groups[self::GROUP_COURANT]['value'])['value']);
        self::assertSame('13.33', self::fields($groups[self::GROUP_COURANT]['share'])['percentDisplay']);

        $netWorth = self::fields($recap['netWorth']);
        self::assertSame('8000.00', self::fields($netWorth['previous'])['value']);
        self::assertSame('9000.00', self::fields($netWorth['current'])['value']);
        self::assertSame('1000.00', self::fields($netWorth['difference'])['value']);
        self::assertSame('12.50', $netWorth['changePercentDisplay']);
        self::assertNull($netWorth['changeReason']);

        self::assertStringNotContainsString('999999', (string) $this->client->getResponse()->getContent());
    }

    public function testTheComparedDayIsThePrecedingMonthEndAndNeverTheFirstDayOfTheMonth(): void
    {
        $this->signIn();
        $this->seedThreeAccounts();
        // A value stamped on the first day of the month already carries that
        // month's opening movements: reading it as N−1 would hide them.
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf->modify('first day of this month'), '4242.00');

        $recap = $this->read();
        $accounts = self::index($recap['accounts'], 'accountId');

        self::assertSame('1000.00', self::fields($accounts[self::CURRENT]['previousValue'])['value']);
        self::assertSame('8000.00', self::fields(self::fields($recap['netWorth'])['previous'])['value']);
    }

    /**
     * A value last observed three days before N−1 remains usable, but its age
     * must travel with that side of the wealth card. N being current must not
     * make the preceding figure look current as well.
     */
    public function testAStalePreviousValuationDegradesOnlyThePreviousWealthQuality(): void
    {
        $this->signIn();
        $this->group(self::GROUP_COURANT, 'Courant');
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'CURRENT', self::GROUP_COURANT);
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf->modify('-3 days'), '100.00');
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '150.00');

        $recap = $this->read();
        $netWorth = self::fields($recap['netWorth']);

        self::assertSame('CURRENT', $recap['quality']);
        self::assertSame('STALE', $netWorth['previousQuality']);
        self::assertSame(3, $netWorth['previousStalestAgeDays']);
        self::assertSame(0, $netWorth['previousMissingValuationCount']);
        self::assertSame(1, $netWorth['previousStaleValuationCount']);
        self::assertSame('CURRENT', $netWorth['quality']);
        self::assertSame(0, $netWorth['stalestAgeDays']);
        self::assertSame(0, $netWorth['missingValuationCount']);
        self::assertSame(0, $netWorth['staleValuationCount']);
    }

    public function testAMissingPreviousValuationPublishesItsOwnCountWithoutDegradingCurrentQuality(): void
    {
        $this->signIn();
        $this->group(self::GROUP_COURANT, 'Courant');
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'CURRENT', self::GROUP_COURANT);
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '150.00');

        $netWorth = self::fields($this->read()['netWorth']);

        self::assertNull($netWorth['previous']);
        self::assertSame('MISSING_VALUATION', $netWorth['previousReason']);
        self::assertSame('MISSING', $netWorth['previousQuality']);
        self::assertNull($netWorth['previousStalestAgeDays']);
        self::assertSame(1, $netWorth['previousMissingValuationCount']);
        self::assertSame(0, $netWorth['previousStaleValuationCount']);
        self::assertSame('CURRENT', $netWorth['quality']);
        self::assertSame(0, $netWorth['missingValuationCount']);
    }

    public function testAnUnfinishedMonthStopsOnTodayAndNeverReadsALaterValuation(): void
    {
        $this->signIn();
        $monthEnd = $this->today->modify('last day of this month');
        if ($this->today->format('Y-m-d') === $monthEnd->format('Y-m-d')) {
            self::markTestSkipped('The running month is complete today; its provisional state cannot be observed.');
        }
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'CURRENT');
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->today->modify('first day of this month'), '100.00');
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $monthEnd, '777777.00');

        $recap = $this->read($this->today->format('Y-m'));

        self::assertTrue($recap['provisional']);
        self::assertSame($this->today->format('Y-m-d'), $recap['currentAsOf']);
        self::assertSame($this->today->modify('first day of this month')->modify('-1 day')->format('Y-m-d'), $recap['previousAsOf']);
        self::assertSame('100.00', self::fields(self::fields($recap['netWorth'])['current'])['value']);
        self::assertStringNotContainsString('777777', (string) $this->client->getResponse()->getContent());
    }

    public function testAMissingValuationLeavesTheGroupAndTheWealthCardNonCalculableRatherThanZero(): void
    {
        $this->signIn();
        $this->seedThreeAccounts();
        $this->account(self::ORPHAN, WorkspaceFixture::OWN_WORKSPACE, 'Livret sans valeur', 'SAVINGS', self::GROUP_LIVRETS);

        $recap = $this->read();

        $groups = self::index($recap['groups'], 'groupId');
        self::assertNull($groups[self::GROUP_LIVRETS]['value']);
        self::assertNull(self::fields($groups[self::GROUP_LIVRETS]['share'])['percentDisplay']);
        self::assertSame('MISSING_VALUATION', self::fields($groups[self::GROUP_LIVRETS]['share'])['reason']);

        $netWorth = self::fields($recap['netWorth']);
        self::assertNull($netWorth['current']);
        self::assertSame('MISSING_VALUATION', $netWorth['currentReason']);
        self::assertNull($netWorth['difference']);
        self::assertNull($netWorth['changePercent']);
        self::assertSame('MISSING', $recap['quality']);

        $accounts = self::index($recap['accounts'], 'accountId');
        self::assertNull(self::fields($accounts[self::ORPHAN]['currentValue'])['value']);
        self::assertSame('MISSING', self::fields($accounts[self::ORPHAN]['currentValue'])['quality']);
    }

    public function testAZeroPriorWealthLeavesTheChangeRateNullWithItsOwnReason(): void
    {
        $this->signIn();
        $this->group(self::GROUP_COURANT, 'Courant');
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'CURRENT', self::GROUP_COURANT);
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf, '0.00');
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '500.00');

        $netWorth = self::fields($this->read()['netWorth']);

        self::assertSame('0.00', self::fields($netWorth['previous'])['value']);
        self::assertSame('500.00', self::fields($netWorth['difference'])['value']);
        self::assertNull($netWorth['changePercent']);
        self::assertSame('ZERO_BASE', $netWorth['changeReason']);
    }

    public function testANegativePriorWealthKeepsAnExactDifferenceAndNamesItsBase(): void
    {
        $this->signIn();
        $this->group(self::GROUP_COURANT, 'Courant');
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Découvert', 'CURRENT', self::GROUP_COURANT);
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf, '-100.00');
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '-50.00');

        $netWorth = self::fields($this->read()['netWorth']);

        self::assertSame('-100.00', self::fields($netWorth['previous'])['value']);
        self::assertSame('50.00', self::fields($netWorth['difference'])['value']);
        self::assertNull($netWorth['changePercent']);
        self::assertSame('NEGATIVE_BASE', $netWorth['changeReason']);
    }

    public function testZeroIncomeLeavesTheSavingsRateNullWhileNegativeNetSavingsStayNegative(): void
    {
        $this->signIn();
        $this->group(self::GROUP_LIVRETS, 'Livrets');
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'CURRENT');
        $this->account(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'SAVINGS', self::GROUP_LIVRETS);
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf, '100.00');
        $this->snapshot(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '100.00');
        $this->snapshot(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf, '100.00');
        $this->snapshot(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '100.00');
        $this->transferPair('a', self::LIVRET_A, self::CURRENT, '250.00');

        $totals = self::fields($this->read()['totals']);

        self::assertSame('0', self::metric($totals, 'cashIncome'));
        self::assertSame('250.00', self::metric($totals, 'savingsWithdrawals'));
        self::assertSame('0', self::metric($totals, 'savingsInflows'));
        self::assertSame('-250.00', self::metric($totals, 'netSavingsTransfers'));
        self::assertNull(self::fields($totals['netSavingsRate'])['value']);
        self::assertSame('ZERO_CASH_INCOME', self::fields($totals['netSavingsRate'])['reason']);
    }

    /**
     * Hand-computed axis facets for the closed month, all on budget-included
     * categories: rent −1000.00 on ESSENTIAL and FIXED, snacks −200.00 on
     * DISCRETIONARY. ESSENTIAL = 1000.00, FIXED = 1000.00,
     * DISCRETIONARY = 200.00 and every other axis an exact zero.
     */
    public function testTheTotalsCardCarriesEveryAnalyticAxisAndItsSources(): void
    {
        $this->signIn();
        $this->seedThreeAccounts();
        $this->category(self::CATEGORY, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->transaction('01', self::CURRENT, '-1000.00', 'EXPENSE', '-1000.00', ['ESSENTIAL', 'FIXED']);
        $this->transaction('02', self::CURRENT, '-200.00', 'EXPENSE', '-200.00', ['DISCRETIONARY']);
        $this->transaction('03', self::CURRENT, '3000.00', 'INCOME');

        $totals = self::fields($this->read()['totals']);

        self::assertSame('3000.00', self::metric($totals, 'cashIncome'));
        self::assertSame('1200.00', self::metric($totals, 'budgetExpenses'));
        $axes = self::index($totals['expensesByAxis'], 'axis');
        self::assertSame(
            ['DISCRETIONARY', 'ESSENTIAL', 'FIXED', 'PERSONAL', 'PROFESSIONAL', 'VARIABLE'],
            array_keys($axes),
        );
        self::assertSame('1000.00', $axes['ESSENTIAL']['value']);
        self::assertSame('1000.00', $axes['FIXED']['value']);
        self::assertSame('200.00', $axes['DISCRETIONARY']['value']);
        self::assertSame('0', $axes['VARIABLE']['value']);
        self::assertSame(['00000000-0000-7000-8000-000000000101'], $axes['ESSENTIAL']['sourceTransactionIds']);
        self::assertSame([[
            'id' => '00000000-0000-7000-8000-000000000101',
            'bookedOn' => $this->currentAsOf->format('Y-m-d'),
            'label' => 'Recap 01',
            'amount' => ['value' => '-1000.00', 'assetCode' => 'EUR'],
            'state' => 'BOOKED',
        ]], $axes['ESSENTIAL']['sourceTransactions']);
        self::assertSame([], $axes['VARIABLE']['sourceTransactions']);
    }

    /**
     * Hand-computed category rows:
     *   Budget expense -1000.00 + refund 250.00 => displayed expense 750.00.
     *   Salary income 3000.00                   => displayed income 3000.00.
     * A category without a movement stays non-calculable (`NO_MOVEMENTS`),
     * and a category owned by another workspace never enters the response.
     */
    public function testCategoryMetricsPreserveLedgerSignsSourcesAndWorkspaceIsolation(): void
    {
        $this->signIn();
        $this->seedThreeAccounts();
        $this->category(self::CATEGORY, WorkspaceFixture::OWN_WORKSPACE, true, 'EXPENSE', 'Budget');
        $this->category(self::INCOME_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, false, 'INCOME', 'Salaire');
        $this->category(self::EMPTY_CATEGORY, WorkspaceFixture::OWN_WORKSPACE, false, 'EXPENSE', 'Vide');
        $this->category(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, true, 'EXPENSE', 'Secret');
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte secret', 'CURRENT');
        $this->transaction('11', self::CURRENT, '-1000.00', 'EXPENSE', '-1000.00');
        $this->transaction('12', self::CURRENT, '250.00', 'REFUND', '250.00');
        $this->transaction('13', self::CURRENT, '3000.00', 'INCOME', '3000.00', [], WorkspaceFixture::OWN_WORKSPACE, self::INCOME_CATEGORY);
        $this->transaction('19', self::OTHER_ACCOUNT, '-999999.00', 'EXPENSE', '-999999.00', [], WorkspaceFixture::OTHER_WORKSPACE, self::OTHER_CATEGORY);

        $categories = self::index(self::fields($this->read()['totals'])['categories'], 'id');

        $categoryIds = array_keys($categories);
        sort($categoryIds, SORT_STRING);
        self::assertSame([self::CATEGORY, self::INCOME_CATEGORY, self::EMPTY_CATEGORY], $categoryIds);
        self::assertSame('Budget', $categories[self::CATEGORY]['label']);
        self::assertSame('EXPENSE', $categories[self::CATEGORY]['type']);
        self::assertSame('750.00', $categories[self::CATEGORY]['value']);
        self::assertSame([
            '00000000-0000-7000-8000-000000000111',
            '00000000-0000-7000-8000-000000000112',
        ], $categories[self::CATEGORY]['sourceTransactionIds']);
        self::assertSame([
            [
                'id' => '00000000-0000-7000-8000-000000000111',
                'bookedOn' => $this->currentAsOf->format('Y-m-d'),
                'label' => 'Recap 11',
                'amount' => ['value' => '-1000.00', 'assetCode' => 'EUR'],
                'state' => 'BOOKED',
            ],
            [
                'id' => '00000000-0000-7000-8000-000000000112',
                'bookedOn' => $this->currentAsOf->format('Y-m-d'),
                'label' => 'Recap 12',
                'amount' => ['value' => '250.00', 'assetCode' => 'EUR'],
                'state' => 'BOOKED',
            ],
        ], $categories[self::CATEGORY]['sourceTransactions']);
        self::assertSame('3000.00', $categories[self::INCOME_CATEGORY]['value']);
        self::assertSame(['00000000-0000-7000-8000-000000000113'], $categories[self::INCOME_CATEGORY]['sourceTransactionIds']);
        self::assertNull($categories[self::EMPTY_CATEGORY]['value']);
        self::assertSame('NO_MOVEMENTS', $categories[self::EMPTY_CATEGORY]['reason']);
        self::assertSame([], $categories[self::EMPTY_CATEGORY]['sourceTransactionIds']);
        self::assertSame([], $categories[self::EMPTY_CATEGORY]['sourceTransactions']);
        self::assertStringNotContainsString('999999', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(self::OTHER_CATEGORY, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('00000000-0000-7000-8000-000000000119', (string) $this->client->getResponse()->getContent());
    }

    public function testAMixedAssetCategoryIsExplicitlyNonCalculableAndKeepsItsSources(): void
    {
        $this->signIn();
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte EUR', 'CURRENT');
        $this->account(self::USD_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte USD', 'CURRENT', asset: 'USD');
        $this->category(self::CATEGORY, WorkspaceFixture::OWN_WORKSPACE, true);
        $this->transaction('21', self::CURRENT, '-1.25', 'EXPENSE', '-1.25');
        $this->transaction('22', self::USD_ACCOUNT, '-2.75', 'EXPENSE', '-2.75', asset: 'USD');

        $category = self::index(self::fields($this->read()['totals'])['categories'], 'id')[self::CATEGORY];

        self::assertNull($category['value']);
        self::assertNull($category['assetCode']);
        self::assertSame('MIXED_ASSETS', $category['reason']);
        self::assertSame([
            '00000000-0000-7000-8000-000000000121',
            '00000000-0000-7000-8000-000000000122',
        ], $category['sourceTransactionIds']);
    }

    public function testAnAccountKeptOutOfNetWorthNeverEntersARowAGroupOrADenominator(): void
    {
        $this->signIn();
        $this->seedThreeAccounts();
        $this->account(self::ORPHAN, WorkspaceFixture::OWN_WORKSPACE, 'Hors patrimoine', 'SAVINGS', self::GROUP_LIVRETS, false);
        $this->snapshot(self::ORPHAN, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, '50000.00');

        $recap = $this->read();

        self::assertCount(3, self::index($recap['accounts'], 'accountId'));
        self::assertSame('7800.00', self::fields(self::index($recap['groups'], 'groupId')[self::GROUP_LIVRETS]['value'])['value']);
        self::assertSame('9000.00', self::fields(self::fields($recap['netWorth'])['current'])['value']);
        self::assertStringNotContainsString('50000.00', (string) $this->client->getResponse()->getContent());
    }

    public function testAMalformedMonthIsRefusedAndAnAnonymousCallerReadsNothing(): void
    {
        $this->signIn();
        foreach (['', '2026-00', '2026-13', '1899-12', '3000-01', '09-2026'] as $month) {
            $this->client->request('GET', '/api/v1/reports/monthly/recap?month='.$month);
            self::assertResponseStatusCodeSame(400);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
        }

        $this->client->request('DELETE', '/api/v1/session');
        $this->client->request('GET', '/api/v1/reports/monthly/recap?month='.$this->closedMonth);
        self::assertResponseStatusCodeSame(401);
    }

    private function seedThreeAccounts(): void
    {
        $this->group(self::GROUP_LIVRETS, 'Livrets');
        $this->group(self::GROUP_COURANT, 'Courant');
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant', 'CURRENT', self::GROUP_COURANT);
        $this->account(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'SAVINGS', self::GROUP_LIVRETS);
        $this->account(self::LDDS, WorkspaceFixture::OWN_WORKSPACE, 'LDDS', 'SAVINGS', self::GROUP_LIVRETS);
        foreach ([
            [self::CURRENT, '1000.00', '1200.00'],
            [self::LIVRET_A, '5000.00', '5500.00'],
            [self::LDDS, '2000.00', '2300.00'],
        ] as [$account, $previous, $current]) {
            $this->snapshot($account, WorkspaceFixture::OWN_WORKSPACE, $this->previousAsOf, $previous);
            $this->snapshot($account, WorkspaceFixture::OWN_WORKSPACE, $this->currentAsOf, $current);
        }
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
    private function read(?string $month = null): array
    {
        $this->client->request('GET', '/api/v1/reports/monthly/recap?month='.($month ?? $this->closedMonth));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return self::fields($data);
    }

    private function group(string $id, string $label): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => $label,
            'parent_id' => null, 'sort_order' => 0, 'depth' => 1, 'version' => 1,
            'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ]);
    }

    private function account(
        string $id,
        string $workspace,
        string $label,
        string $kind,
        ?string $groupId = null,
        bool $includeInNetWorth = true,
        string $asset = 'EUR',
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => $asset, 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => $includeInNetWorth, 'include_in_emergency_fund' => false,
            'opened_on' => $this->previousAsOf->modify('-1 year')->format('Y-m-d'), 'version' => 1,
            'created_at' => $this->previousAsOf->modify('-1 year')->format('Y-m-d').' 12:00:00+00',
            'updated_at' => $this->previousAsOf->modify('-1 year')->format('Y-m-d').' 12:00:00+00',
            'primary_group_id' => $groupId,
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function snapshot(string $account, string $workspace, \DateTimeImmutable $day, string $amount): void
    {
        $iso = $day->format('Y-m-d');
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($workspace.$account.$iso), 0, 8).'-0000-7000-8000-000000000000',
            'workspace_id' => $workspace, 'account_id' => $account, 'as_of' => $iso, 'amount_value' => $amount,
            'amount_literal' => $amount, 'amount_asset' => 'EUR', 'source' => 'MANUAL',
            'reconciliation_status' => 'RECONCILED', 'version' => 1,
            'recorded_at' => $iso.' 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function category(
        string $id,
        string $workspace,
        bool $included,
        string $type = 'EXPENSE',
        string $label = 'Budget',
    ): void {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspace, 'type' => $type, 'label' => $label,
            'default_analytic_axes' => '[]', 'budget_included' => $included, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-01-01 12:00:00+00', 'updated_at' => '2026-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    /** @param list<string> $axes */
    private function transaction(
        string $suffix,
        string $account,
        string $amount,
        string $nature,
        ?string $splitAmount = null,
        array $axes = [],
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $category = self::CATEGORY,
        string $asset = 'EUR',
    ): void {
        $id = '00000000-0000-7000-8000-0000000001'.$suffix;
        $bookedOn = $this->currentAsOf->format('Y-m-d');
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => $asset,
            'amount_value' => $amount, 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => $nature,
            'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'Recap '.$suffix,
            'version' => 1, 'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
        ]);
        if (null !== $splitAmount) {
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000002'.$suffix, 'workspace_id' => $workspace,
                'transaction_id' => $id, 'category_id' => $category, 'amount_value' => $splitAmount,
                'amount_scale' => 2, 'asset_code' => $asset, 'analytic_axes' => json_encode($axes, JSON_THROW_ON_ERROR),
                'created_at' => $bookedOn.' 12:00:00+00',
            ]);
        }
    }

    private function transferPair(string $suffix, string $sourceAccount, string $targetAccount, string $amount): void
    {
        $this->transaction($suffix.'1', $sourceAccount, '-'.$amount, 'TRANSFER');
        $this->transaction($suffix.'2', $targetAccount, $amount, 'TRANSFER');
        $bookedOn = $this->currentAsOf->format('Y-m-d');
        $this->connection->insert('transaction_transfers', [
            'id' => '00000000-0000-7000-8000-0000000004'.$suffix.'3',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'source_transaction_id' => '00000000-0000-7000-8000-0000000001'.$suffix.'1',
            'target_transaction_id' => '00000000-0000-7000-8000-0000000001'.$suffix.'2',
            'version' => 1,
            'created_at' => $bookedOn.' 12:00:00+00',
            'updated_at' => $bookedOn.' 12:00:00+00',
        ]);
    }

    /** @param array<string, mixed> $totals */
    private static function metric(array $totals, string $key): string
    {
        $metric = self::fields($totals[$key]);
        self::assertIsString($metric['value'], $key.' should carry a value');

        return $metric['value'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function index(mixed $rows, string $key): array
    {
        self::assertIsArray($rows);
        $indexed = [];
        foreach ($rows as $row) {
            $fields = self::fields($row);
            $identifier = $fields[$key];
            self::assertIsString($identifier);
            $indexed[$identifier] = $fields;
        }

        return $indexed;
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

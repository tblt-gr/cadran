<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MultiYearReportControllerTest extends WebTestCase
{
    use ClosesPeriods;

    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000a1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000a9';
    private const string MID_RANGE_ACCOUNT = '00000000-0000-7000-8000-0000000000a5';
    private const string CATEGORY = '00000000-0000-7000-8000-0000000000c1';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    /** A fully elapsed year, so the report never depends on the day the suite runs. */
    private int $year;
    private int $nextSuffix = 0;

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
        $this->year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y') - 1;
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testEachYearCellEqualsTheAnnualReportAggregateForTheSameYearAndSetting(): void
    {
        $this->signIn();
        $this->seedYear($this->year);
        $this->seedYear($this->year - 1);
        $this->put(['cashIncome', 'cashSavingsRate'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $annualPrevious = $this->get('/api/v1/reports/annual/'.($this->year - 1));
        $annualCurrent = $this->get('/api/v1/reports/annual/'.$this->year);

        $rows = self::rowsOf($multi['years']);
        self::assertCount(2, $rows);
        self::assertSame($this->year - 1, $rows[0]['year']);
        self::assertSame($this->year, $rows[1]['year']);
        self::assertSame('COMPLETE', $rows[0]['state']);
        self::assertSame('COMPLETE', $rows[1]['state']);
        self::assertSame(12, $rows[0]['countedMonths']);

        foreach (['cashIncome', 'cashSavingsRate'] as $column) {
            self::assertDecimal(
                self::stringOf(self::fields(self::fields($annualPrevious['aggregates'])[$column])['total']),
                self::cell($rows[0], $column)['value'],
            );
            self::assertDecimal(
                self::stringOf(self::fields(self::fields($annualCurrent['aggregates'])[$column])['total']),
                self::cell($rows[1], $column)['value'],
            );
        }
    }

    public function testAPartialYearMakesItsOwnChangeNullWithPartialYear(): void
    {
        $this->signIn();
        $runningYear = $this->year + 1;
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $runningYear.'-01-10');
        $this->closeYearWithPolicyOne($this->year - 1);
        $this->closeYearWithPolicyOne($this->year);
        $this->policyVersionTwoFrom($runningYear.'-01-01 00:00:00+00');
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $runningYear);
        $rows = self::rowsOf($multi['years']);

        self::assertSame('COMPLETE', $rows[0]['state']);
        self::assertSame('COMPLETE', $rows[1]['state']);
        self::assertSame('PARTIAL', $rows[2]['state']);
        self::assertSame(1, self::fields($rows[1]['metricPolicy'])['version']);
        self::assertSame(2, self::fields($rows[2]['metricPolicy'])['version']);
        self::assertNotNull(self::cell($rows[1], 'cashIncome')['change']);
        self::assertNull(self::cell($rows[1], 'cashIncome')['changeReason']);
        self::assertNull(self::cell($rows[2], 'cashIncome')['change']);
        self::assertSame('PARTIAL_YEAR', self::cell($rows[2], 'cashIncome')['changeReason']);
    }

    public function testAPolicyChangeBetweenTwoCompleteYearsGivesAPolicyMismatchChangeAndSummary(): void
    {
        $this->signIn();
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $this->closeYearWithPolicyOne($this->year - 1);
        $this->policyVersionTwoFrom(($this->year - 1).'-12-15 00:00:00+00');
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($multi['years']);

        self::assertSame('COMPLETE', $rows[0]['state']);
        self::assertSame('COMPLETE', $rows[1]['state']);
        self::assertNull(self::cell($rows[1], 'cashIncome')['change']);
        self::assertSame('POLICY_MISMATCH', self::cell($rows[1], 'cashIncome')['changeReason']);
        $summary = self::fields(self::fields($multi['summary'])['cashIncome']);
        self::assertNull($summary['average']);
        self::assertSame('MIXED_METRIC_POLICIES', $summary['averageReason']);
    }

    public function testAPreviousYearIncomeOfZeroGivesAZeroBaseChangeRatioButAnExactChange(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year - 1, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '100.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', $day);
        }
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $day);
        }
        $this->put(['netSavingsTransfers'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($multi['years']);

        $cell = self::cell($rows[1], 'netSavingsTransfers');
        self::assertNotNull($cell['change']);
        self::assertNull($cell['changeRatio']);
        self::assertSame('ZERO_BASE', $cell['changeReason']);
    }

    public function testARangeOfSixteenYearsIsRefusedAndFromAfterToIsRefused(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/reports/multi-year?from='.($this->year - 15).'&to='.$this->year);
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('/problems/range-too-large', self::fields(json_decode((string) $this->client->getResponse()->getContent(), true))['type']);

        $this->client->request('GET', '/api/v1/reports/multi-year?from='.$this->year.'&to='.($this->year - 1));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid-year-range', self::fields(json_decode((string) $this->client->getResponse()->getContent(), true))['type']);
    }

    public function testAnAccountOpenedTheFollowingYearIsNullWithAccountNotOpenTheYearBefore(): void
    {
        $this->signIn();
        $this->connection->insert('account_financial_accounts', [
            'id' => self::ACCOUNT, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'label' => 'Nouveau', 'asset_code' => 'EUR', 'kind' => 'CURRENT',
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => $this->year.'-01-01', 'version' => 1,
            'created_at' => $this->year.'-01-01 12:00:00+00', 'updated_at' => $this->year.'-01-01 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $this->year.'-01-10');
        $this->put(['account:'.self::ACCOUNT], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($multi['years']);

        $cell = self::cell($rows[0], 'account:'.self::ACCOUNT);
        self::assertNull($cell['value']);
        self::assertSame('ACCOUNT_NOT_OPEN', $cell['reason']);
    }

    public function testAnonymousAccessAndEmptyWorkspaceIsRefusedOrEmpty(): void
    {
        $this->client->request('GET', '/api/v1/reports/multi-year');
        self::assertResponseStatusCodeSame(401);

        $this->signIn();
        $report = $this->multiYear();
        self::assertSame([], $report['years']);
        self::assertNull($report['availableFrom']);
    }

    public function testAnEmptyWorkspaceReturnsNoRowsForAnExplicitValidRange(): void
    {
        $this->signIn();

        $report = $this->multiYear($this->year - 2, $this->year);

        self::assertSame($this->year - 2, $report['from']);
        self::assertSame($this->year, $report['to']);
        self::assertNull($report['availableFrom']);
        self::assertSame([], $report['years']);
    }

    public function testAnotherWorkspaceReceivesOnlyItsOwnYearsAndAnEmptyListWithNoData(): void
    {
        $this->signIn();
        $this->seedYear($this->year);
        $this->put(['cashIncome'], 'exclude', 0);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $report = $this->multiYear();

        self::assertSame([], $report['years']);
        self::assertStringNotContainsString('12000', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherWorkspaceNeverSeesTheOwnWorkspaceYears(): void
    {
        $this->signIn();
        $this->seedYear($this->year);
        $this->put(['cashIncome'], 'exclude', 0);
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->transaction($this->nextSuffix(), self::OTHER_ACCOUNT, '999999.00', 'INCOME', $this->year.'-02-10', WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $report = $this->multiYear($this->year, $this->year);

        $rows = self::rowsOf($report['years']);
        self::assertCount(1, $rows);
        self::assertDecimal('999999', self::cell($rows[0], 'cashIncome')['value']);
        self::assertStringNotContainsString('12000', (string) $this->client->getResponse()->getContent());
    }

    public function testInflationAdjustedIsAlwaysFalse(): void
    {
        $this->signIn();
        $this->seedYear($this->year);

        $report = $this->multiYear($this->year, $this->year);

        self::assertFalse($report['inflationAdjusted']);
    }

    public function testTheDefaultRangeIsClampedToFifteenYearsForALongLivedWorkspace(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $runningYear = $this->year + 1;
        for ($year = $runningYear - 16; $year <= $runningYear; ++$year) {
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '100.00', 'INCOME', $year.'-06-10');
        }

        $report = $this->multiYear();

        $rows = self::rowsOf($report['years']);
        self::assertCount(15, $rows);
        self::assertSame($runningYear - 16, $report['availableFrom']);
        self::assertSame($runningYear - 14, $report['from']);
        self::assertSame($runningYear, $report['to']);
        self::assertSame($runningYear - 14, $rows[0]['year']);
    }

    public function testANonTrivialChangeAndSummaryAreComputedExactly(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        for ($month = 1; $month <= 12; ++$month) {
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '6500.00', 'INCOME', sprintf('%d-%02d-10', $this->year - 1, $month));
        }
        for ($month = 1; $month <= 12; ++$month) {
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '6890.00', 'INCOME', sprintf('%d-%02d-10', $this->year, $month));
        }
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($multi['years']);

        self::assertDecimal('78000', self::cell($rows[0], 'cashIncome')['value']);
        self::assertDecimal('82680', self::cell($rows[1], 'cashIncome')['value']);
        $cell = self::cell($rows[1], 'cashIncome');
        self::assertDecimal('4680', self::stringOf($cell['change']));
        self::assertDecimal('0.06', self::stringOf($cell['changeRatio']));

        $summary = self::fields(self::fields($multi['summary'])['cashIncome']);
        self::assertDecimal('80340', self::stringOf($summary['average']));
        $minimum = self::fields($summary['minimum']);
        self::assertSame($this->year - 1, $minimum['year']);
        self::assertDecimal('78000', self::stringOf($minimum['value']));
        $maximum = self::fields($summary['maximum']);
        self::assertSame($this->year, $maximum['year']);
        self::assertDecimal('82680', self::stringOf($maximum['value']));
    }

    public function testResponseArithmeticHandlesMaximumWidthAnnualValuesWithoutStorageOverflow(): void
    {
        $this->signIn();
        $maximum = '99999999999999999999999999';
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, $maximum, 'INCOME', ($this->year - 2).'-01-10');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, $maximum, 'INCOME', ($this->year - 1).'-01-10');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '-'.$maximum, 'EXPENSE', $this->year.'-01-10', split: self::CATEGORY);
        $this->put(['cashIncome', 'cashSavingsRate'], 'exclude', 0);

        $meanReport = $this->multiYear($this->year - 2, $this->year - 1);
        $summary = self::fields(self::fields($meanReport['summary'])['cashIncome']);
        self::assertSame($maximum.'.000000000000000000000000', $summary['average']);
        $rateSummary = self::fields(self::fields($meanReport['summary'])['cashSavingsRate']);
        self::assertSame('1.000000000000000000000000', $rateSummary['average']);

        $this->put(['budgetSurplus'], 'exclude', 1);
        $changeReport = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($changeReport['years']);
        $cell = self::cell($rows[1], 'budgetSurplus');
        self::assertSame('-199999999999999999999999998.00', $cell['change']);
        self::assertSame('-2.000000000000000000000000', $cell['changeRatio']);
        self::assertNull($cell['changeReason']);
    }

    public function testANegativeBasePreviousYearGivesANegativeBaseChangeRatioButAnExactChange(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year - 1, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '100.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-1000.00', 'EXPENSE', $day, split: self::CATEGORY);
        }
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', $day, split: self::CATEGORY);
        }
        $this->put(['budgetSurplus'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($multi['years']);

        self::assertDecimal('-10800', self::cell($rows[0], 'budgetSurplus')['value']);
        self::assertDecimal('10800', self::cell($rows[1], 'budgetSurplus')['value']);
        $cell = self::cell($rows[1], 'budgetSurplus');
        self::assertDecimal('21600', self::stringOf($cell['change']));
        self::assertNull($cell['changeRatio']);
        self::assertSame('NEGATIVE_BASE', $cell['changeReason']);
    }

    public function testNoCompleteYearGivesAnEmptyPopulationSummary(): void
    {
        $this->signIn();
        $runningYear = $this->year + 1;
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $runningYear.'-01-10');
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($runningYear, $runningYear);
        $summary = self::fields(self::fields($multi['summary'])['cashIncome']);

        self::assertNull($summary['average']);
        self::assertSame('EMPTY_POPULATION', $summary['averageReason']);
    }

    public function testARateChangeIsInRatioUnitsNotPercentageOfPercentage(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year - 1, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', $day, split: self::CATEGORY);
        }
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-500.00', 'EXPENSE', $day, split: self::CATEGORY);
        }
        $this->put(['cashSavingsRate'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $rows = self::rowsOf($multi['years']);

        self::assertDecimal('0.9', self::cell($rows[0], 'cashSavingsRate')['value']);
        self::assertDecimal('0.5', self::cell($rows[1], 'cashSavingsRate')['value']);
        $cell = self::cell($rows[1], 'cashSavingsRate');
        self::assertDecimal('-0.4', self::stringOf($cell['change']));
        self::assertNull($cell['changeRatio']);
    }

    public function testAStockCellCarriesTheAsOfMonthOfItsPeriodEnd(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->year.'-12-31', '5000.00', 'RECONCILED');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '100.00', 'INCOME', $this->year.'-01-10');
        $this->put(['account:'.self::ACCOUNT], 'exclude', 0);

        $multi = $this->multiYear($this->year, $this->year);
        $rows = self::rowsOf($multi['years']);

        $cell = self::cell($rows[0], 'account:'.self::ACCOUNT);
        self::assertDecimal('5000', self::stringOf($cell['value']));
        self::assertSame(sprintf('%d-12', $this->year), $cell['asOf']);
        self::assertNull($cell['reason']);
    }

    public function testEachYearCellEqualsTheAnnualReportAggregateWithIncompleteMonthsInclude(): void
    {
        $this->signIn();
        $this->seedYear($this->year);
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->get('/api/v1/reports/multi-year?from='.$this->year.'&to='.$this->year.'&incompleteMonths=include');
        $annual = $this->get('/api/v1/reports/annual/'.$this->year.'?incompleteMonths=include');

        $row = self::rowsOf($multi['years'])[0];
        self::assertSame('include', $multi['incompleteMonths']);
        self::assertDecimal(
            self::stringOf(self::fields(self::fields($annual['aggregates'])['cashIncome'])['total']),
            self::stringOf(self::cell($row, 'cashIncome')['value']),
        );
    }

    public function testFromBelowNineteenHundredAndToAboveTheCurrentYearAreRefused(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/reports/multi-year?from=1899&to='.$this->year);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid-year-range', self::fields(json_decode((string) $this->client->getResponse()->getContent(), true))['type']);

        $this->client->request('GET', '/api/v1/reports/multi-year?from='.$this->year.'&to='.($this->year + 2));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid-year-range', self::fields(json_decode((string) $this->client->getResponse()->getContent(), true))['type']);
    }

    public function testANonNumericFromParameterIsARefusedRequest(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/v1/reports/multi-year?from=abc&to='.$this->year);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTheWholePeriodAggregateSumsAveragesMediansAndExtremesAcrossEveryYearOfTheRange(): void
    {
        $this->signIn();
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashIncome']);

        self::assertDecimal('156000', self::stringOf($detailed['total']));
        self::assertNull($detailed['totalReason']);
        self::assertDecimal('6500', self::stringOf($detailed['average']));
        self::assertDecimal('6500', self::stringOf($detailed['median']));
        $minimum = self::fields($detailed['minimum']);
        self::assertDecimal('1000', self::stringOf($minimum['value']));
        self::assertSame(sprintf('%d-01', $this->year - 1), $minimum['month']);
        $maximum = self::fields($detailed['maximum']);
        self::assertDecimal('12000', self::stringOf($maximum['value']));
        self::assertSame(sprintf('%d-12', $this->year - 1), $maximum['month']);
        self::assertNull($detailed['extremesReason']);
    }

    public function testTheWholePeriodAggregateIsNullWithMixedMetricPoliciesWhenTheRangeCrossesAPolicyChange(): void
    {
        $this->signIn();
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $this->closeYearWithPolicyOne($this->year - 1);
        $this->policyVersionTwoFrom(($this->year - 1).'-12-15 00:00:00+00');
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashIncome']);

        self::assertNull($detailed['total']);
        self::assertSame('MIXED_METRIC_POLICIES', $detailed['totalReason']);
        self::assertNull($detailed['average']);
        self::assertSame('MIXED_METRIC_POLICIES', $detailed['averageReason']);
        self::assertNull($detailed['median']);
        self::assertSame('MIXED_METRIC_POLICIES', $detailed['medianReason']);
    }

    public function testTheWholePeriodAggregateRespectsTheIncompleteMonthsSetting(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $today->format('Y-m-01'));
        $this->put(['cashIncome'], 'exclude', 0);
        $runningYear = (int) $today->format('Y');

        $excluded = $this->multiYear($runningYear, $runningYear);
        $included = $this->get('/api/v1/reports/multi-year?from='.$runningYear.'&to='.$runningYear.'&incompleteMonths=include');

        $excludedCell = self::fields(self::fields($excluded['detailedPeriod'])['cashIncome']);
        $includedCell = self::fields(self::fields($included['detailedPeriod'])['cashIncome']);

        self::assertDecimal('1000', self::stringOf($excludedCell['total']));
        self::assertSame('EMPTY_POPULATION', $excludedCell['averageReason']);
        self::assertDecimal('1000', self::stringOf($includedCell['average']));
        self::assertDecimal('1000', self::stringOf($includedCell['median']));
    }

    public function testTheWholePeriodRateAggregateIsNullWithZeroDenominatorWhenCashIncomeIsAlwaysZero(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        for ($month = 1; $month <= 12; ++$month) {
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', sprintf('%d-%02d-10', $this->year, $month), split: self::CATEGORY);
        }
        $this->put(['cashSavingsRate'], 'exclude', 0);

        $multi = $this->multiYear($this->year, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashSavingsRate']);

        self::assertNull($detailed['total']);
        self::assertSame('ZERO_DENOMINATOR', $detailed['totalReason']);
        self::assertNull($detailed['average']);
        self::assertSame('ZERO_DENOMINATOR', $detailed['averageReason']);
    }

    public function testAnotherWorkspaceNeverSeesTheOwnWorkspaceWholePeriodAggregate(): void
    {
        $this->signIn();
        $this->seedYear($this->year);
        $this->put(['cashIncome'], 'exclude', 0);
        $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Secret', 'CURRENT');
        $this->transaction($this->nextSuffix(), self::OTHER_ACCOUNT, '999999.00', 'INCOME', $this->year.'-02-10', WorkspaceFixture::OTHER_WORKSPACE);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $multi = $this->multiYear($this->year, $this->year);

        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashIncome']);
        self::assertDecimal('999999', self::stringOf($detailed['total']));
        self::assertStringNotContainsString('78000', (string) $this->client->getResponse()->getContent());
    }

    public function testTheWholePeriodAggregateExcludesMonthsBeforeAnAccountOpenedMidRange(): void
    {
        $this->signIn();
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $this->account(self::MID_RANGE_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Nouveau compte', 'SAVINGS', ($this->year - 1).'-04-01');
        $index = 0;
        for ($year = $this->year - 1; $year <= $this->year; ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                if ($year === $this->year - 1 && $month < 4) {
                    // Before the account opened: no snapshot is recorded on purpose, so a value
                    // leaking into the whole-period population here would surface as a missing
                    // month rather than a correct figure.
                    continue;
                }
                ++$index;
                $this->snapshot(self::MID_RANGE_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, sprintf('%d-%02d-28', $year, $month), sprintf('%d.00', $index * 100), 'RECONCILED');
            }
        }
        $this->put(['account:'.self::MID_RANGE_ACCOUNT], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['account:'.self::MID_RANGE_ACCOUNT]);

        self::assertNull($detailed['total']);
        self::assertSame('NOT_ADDITIVE', $detailed['totalReason']);
        self::assertDecimal('1100', self::stringOf($detailed['average']));
        self::assertDecimal('1100', self::stringOf($detailed['median']));
        $minimum = self::fields($detailed['minimum']);
        self::assertDecimal('100', self::stringOf($minimum['value']));
        self::assertSame(sprintf('%d-04', $this->year - 1), $minimum['month']);
        $maximum = self::fields($detailed['maximum']);
        self::assertDecimal('2100', self::stringOf($maximum['value']));
        self::assertSame(sprintf('%d-12', $this->year), $maximum['month']);
    }

    public function testTheWholePeriodAggregateExposesQualityForAMixedNoDataCompleteAndProvisionalRange(): void
    {
        $this->signIn();
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $runningYear = $this->year + 1;
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', $runningYear.'-01-10');
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->get('/api/v1/reports/multi-year?from='.($this->year - 2).'&to='.$runningYear.'&incompleteMonths=include');
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashIncome']);

        self::assertSame('PARTIAL', $detailed['quality']);
    }

    public function testTheWholePeriodAggregateHasCompleteQualityWhenEveryYearIsFullyElapsed(): void
    {
        $this->signIn();
        $this->seedYear($this->year - 1);
        $this->seedYear($this->year);
        $this->put(['cashIncome'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashIncome']);

        self::assertSame('COMPLETE', $detailed['quality']);
    }

    public function testTheWholePeriodRateAggregateUsesRatioOfSumsNotMeanOfMonthlyRates(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1000.00', 'INCOME', ($this->year - 1).'-01-10');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '-500.00', 'EXPENSE', ($this->year - 1).'-01-10', split: self::CATEGORY);
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '3000.00', 'INCOME', $this->year.'-01-10');
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '-2700.00', 'EXPENSE', $this->year.'-01-10', split: self::CATEGORY);
        $this->put(['cashSavingsRate'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['cashSavingsRate']);

        // Ratio of sums: (500 + 300) / (1000 + 3000) = 0.2, not the mean of the monthly rates
        // (0.5 + 0.1) / 2 = 0.3.
        self::assertDecimal('0.2', self::stringOf($detailed['total']));
        self::assertDecimal('0.2', self::stringOf($detailed['average']));
    }

    public function testTheWholePeriodStockAggregateHasNoTotalButRealAverageMedianAndExtremes(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        // The workspace's first data month comes from its earliest transaction, not its earliest
        // balance snapshot, so a transaction anchors the range even though this test only reads
        // the account's stock aggregate.
        $this->transaction($this->nextSuffix(), self::ACCOUNT, '1.00', 'INCOME', ($this->year - 1).'-01-05');
        // A snapshot on the first month of the range carries a real value forward for every month:
        // without it, the months before the first snapshot would be MISSING_VALUATION.
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, ($this->year - 1).'-01-28', '500.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, ($this->year - 1).'-06-28', '1000.00', 'RECONCILED');
        $this->snapshot(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, $this->year.'-06-28', '3000.00', 'RECONCILED');
        $this->put(['account:'.self::ACCOUNT], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['account:'.self::ACCOUNT]);

        self::assertNull($detailed['total']);
        self::assertSame('NOT_ADDITIVE', $detailed['totalReason']);
        self::assertNotNull($detailed['average']);
        self::assertNotNull($detailed['median']);
        self::assertNotNull($detailed['minimum']);
        self::assertNotNull($detailed['maximum']);
    }

    public function testTheWholePeriodAggregateIsNullWithMixedAssetsWhenACategorysCurrencyDiffersAcrossYears(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        for ($month = 1; $month <= 12; ++$month) {
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', sprintf('%d-%02d-10', $this->year - 1, $month), split: self::CATEGORY);
        }
        for ($month = 1; $month <= 12; ++$month) {
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', sprintf('%d-%02d-10', $this->year, $month), split: self::CATEGORY, asset: 'USD');
        }
        $this->put(['category:'.self::CATEGORY], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['category:'.self::CATEGORY]);

        self::assertNull($detailed['total']);
        self::assertSame('MIXED_ASSETS', $detailed['totalReason']);
        self::assertNull($detailed['average']);
        self::assertSame('MIXED_ASSETS', $detailed['averageReason']);
    }

    public function testTheWholePeriodAggregateSumsAveragesAndMediansNegativeAndPositiveMonthsExactly(): void
    {
        $this->signIn();
        $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        $this->category(self::CATEGORY, 'Loyer');
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year - 1, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '100.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-1000.00', 'EXPENSE', $day, split: self::CATEGORY);
        }
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $this->year, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '1500.00', 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, '-100.00', 'EXPENSE', $day, split: self::CATEGORY);
        }
        $this->put(['budgetSurplus'], 'exclude', 0);

        $multi = $this->multiYear($this->year - 1, $this->year);
        $detailed = self::fields(self::fields($multi['detailedPeriod'])['budgetSurplus']);

        self::assertDecimal('6000', self::stringOf($detailed['total']));
        self::assertNull($detailed['totalReason']);
        self::assertDecimal('250', self::stringOf($detailed['average']));
        self::assertDecimal('250', self::stringOf($detailed['median']));
        $minimum = self::fields($detailed['minimum']);
        self::assertDecimal('-900', self::stringOf($minimum['value']));
        self::assertSame(sprintf('%d-01', $this->year - 1), $minimum['month']);
        $maximum = self::fields($detailed['maximum']);
        self::assertDecimal('1400', self::stringOf($maximum['value']));
        self::assertSame(sprintf('%d-01', $this->year), $maximum['month']);
    }

    private function seedYear(int $year): void
    {
        if (!$this->connection->fetchOne('SELECT 1 FROM account_financial_accounts WHERE id = ?', [self::ACCOUNT])) {
            $this->account(self::ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Courant', 'CURRENT');
        }
        if (!$this->connection->fetchOne('SELECT 1 FROM category_categories WHERE id = ?', [self::CATEGORY])) {
            $this->category(self::CATEGORY, 'Loyer');
        }
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%d-%02d-10', $year, $month);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, sprintf('%d.00', $month * 1000), 'INCOME', $day);
            $this->transaction($this->nextSuffix(), self::ACCOUNT, sprintf('-%d.00', $month * 100), 'EXPENSE', $day, split: self::CATEGORY);
        }
    }

    private function nextSuffix(): string
    {
        return sprintf('%02x', $this->nextSuffix++);
    }

    /** @return array<string, mixed> */
    private function multiYear(?int $from = null, ?int $to = null): array
    {
        $query = [];
        if (null !== $from) {
            $query[] = 'from='.$from;
        }
        if (null !== $to) {
            $query[] = 'to='.$to;
        }
        $suffix = [] === $query ? '' : '?'.implode('&', $query);

        return $this->get('/api/v1/reports/multi-year'.$suffix);
    }

    private function policyVersionTwoFrom(string $activeFrom): void
    {
        $this->connection->insert('reporting_metric_policies', [
            'id' => '00000000-0000-7000-8000-000000000002', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'version' => 2,
            'label' => 'Politique deux', 'cash_excluded_account_kinds' => '[]',
            'savings_rate_formula' => 'BUDGET_SURPLUS_OVER_CASH_INCOME',
            'net_savings_rate_formula' => 'NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME',
            'created_at' => '2020-01-01 10:00:00+00', 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
        $this->connection->insert('reporting_metric_policy_activations', [
            'id' => '00000000-0000-7000-8000-000000000012', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'policy_version' => 2,
            'active_from' => $activeFrom, 'created_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function closeYearWithPolicyOne(int $year): void
    {
        for ($month = 1; $month <= 12; ++$month) {
            $this->connection->insert('account_period_closures', [
                'id' => sprintf('00000000-0000-7000-8000-%012d', $year * 100 + $month), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
                'year' => $year, 'month' => $month, 'closed_at' => $year.'-12-01 10:00:00+00',
                'closed_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
            ]);
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

    /** @param list<string> $columns */
    private function put(array $columns, string $incomplete, int $version): void
    {
        $this->call('PUT', '/api/v1/reports/annual/preferences', ['columns' => $columns, 'incompleteMonths' => $incomplete, 'version' => $version]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return self::fields(json_decode((string) $this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return self::fields(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function account(string $id, string $workspace, string $label, string $kind, string $openedOn = '2000-01-01'): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => 'EUR', 'kind' => $kind,
            'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => $openedOn, 'version' => 1,
            'created_at' => $openedOn.' 12:00:00+00', 'updated_at' => $openedOn.' 12:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    private function snapshot(string $account, string $workspace, string $day, string $amount, string $status, string $asset = 'EUR'): void
    {
        $this->connection->insert('account_balance_snapshots', [
            'id' => substr(sha1($workspace.$account.$day), 0, 8).'-0000-7000-8000-000000000000',
            'workspace_id' => $workspace, 'account_id' => $account, 'as_of' => $day, 'amount_value' => $amount,
            'amount_literal' => $amount, 'amount_asset' => $asset, 'source' => 'MANUAL', 'reconciliation_status' => $status,
            'version' => 1, 'recorded_at' => $day.' 12:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID,
        ]);
    }

    private function category(string $id, string $label): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE', 'label' => $label,
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2000-01-01 12:00:00+00', 'updated_at' => '2000-01-01 12:00:00+00',
        ], ['budget_included' => ParameterType::BOOLEAN]);
    }

    private function transaction(
        string $suffix,
        string $account,
        string $amount,
        string $nature,
        string $bookedOn,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        ?string $split = null,
        string $asset = 'EUR',
    ): void {
        $id = '00000000-0000-7000-8000-0000000003'.$suffix;
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $account, 'asset_code' => $asset,
            'amount_value' => $amount, 'amount_scale' => 2, 'state' => 'BOOKED', 'nature' => $nature,
            'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => 'MultiYear '.$suffix,
            'version' => 1, 'created_at' => $bookedOn.' 12:00:00+00', 'updated_at' => $bookedOn.' 12:00:00+00',
        ]);
        if (null !== $split) {
            $this->connection->insert('transaction_splits', [
                'id' => '00000000-0000-7000-8000-0000000004'.$suffix, 'workspace_id' => $workspace,
                'transaction_id' => $id, 'category_id' => $split, 'amount_value' => $amount,
                'amount_scale' => 2, 'asset_code' => $asset, 'created_at' => $bookedOn.' 12:00:00+00',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function cell(array $row, string $column): array
    {
        return self::fields(self::fields($row['cells'])[$column]);
    }

    /** @return list<array<string, mixed>> */
    private static function rowsOf(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(static fn (mixed $row): array => self::fields($row), $value));
    }

    private static function stringOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function assertDecimal(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame(0, DecimalValue::fromString($expected)->compareTo(DecimalValue::fromString($actual)), $expected.' vs '.$actual);
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\TransactionFacetFixture as F;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/transactions/facets: the number beside every option of the filter column,
 * over the hand-computed data set of {@see F}.
 */
final class TransactionFacetControllerTest extends WebTestCase
{
    private const string UNKNOWN_ACCOUNT = '00000000-0000-7000-8000-00000000aaaa';

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
        $this->resetThrottling();

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        (new F($connection))->seed();

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->signIn();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testTheSixSectionsCountTheDefaultScopeAsIntegersAndNeverTheOtherWorkspace(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $facets = $this->decode();
        self::assertSame(['accountId', 'categoryId', 'nature', 'state', 'source', 'axis'], array_keys($facets));
        self::assertSame([F::CURRENT => 4, F::SAVINGS => 2], self::sorted(self::section($facets, 'accountId')));
        self::assertSame([F::FOOD => 1, F::GROCERIES => 3, F::RESTAURANTS => 1, F::TRANSPORT => 1], self::sorted(self::section($facets, 'categoryId')));
        self::assertSame(['EXPENSE' => 4, 'INCOME' => 1, 'REFUND' => 1], self::sorted(self::section($facets, 'nature')));
        self::assertSame(['BOOKED' => 5, 'PENDING' => 1, 'REJECTED' => 1, 'VOIDED' => 1], self::sorted(self::section($facets, 'state')));
        self::assertSame(['IMPORT' => 3, 'MANUAL' => 3], self::sorted(self::section($facets, 'source')));
        self::assertSame(['ESSENTIAL' => 4, 'VARIABLE' => 2], self::sorted(self::section($facets, 'axis')));
    }

    public function testASectionWithNoMatchIsAnEmptyJsonObjectNeverAnArray(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets?from=2030-01-01');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            '{"accountId":{},"categoryId":{},"nature":{},"state":{},"source":{},"axis":{}}',
            $this->client->getResponse()->getContent(),
        );
    }

    public function testCheckingAnOptionLeavesTheCountsOfItsSiblingsUnchanged(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets?direction=OUTFLOW');
        $outflows = $this->decode();
        self::assertSame([F::CURRENT => 3, F::SAVINGS => 1], self::section($outflows, 'accountId'));

        $this->client->request('GET', '/api/v1/transactions/facets?direction=OUTFLOW&accountId='.F::CURRENT);
        $checked = $this->decode();
        self::assertSame(self::section($outflows, 'accountId'), self::section($checked, 'accountId'));
        // The other sections do narrow to the checked account.
        self::assertSame(['EXPENSE' => 3], self::section($checked, 'nature'));
    }

    public function testACategoryCountIncludesItsSplitLinesAndATransactionSplitAcrossTwoCountsOnceInEach(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets');
        $direct = self::section($this->decode(), 'categoryId');

        // t2 is split across Groceries and Restaurants: it counts once in each.
        self::assertSame(3, $direct[F::GROCERIES]);
        self::assertSame(1, $direct[F::RESTAURANTS]);

        $this->client->request('GET', '/api/v1/transactions/facets?includeDescendants=true');
        $rolledUp = self::section($this->decode(), 'categoryId');
        // Food covers t1, t2 (once, though both its splits are below Food), t5 and t6.
        self::assertSame(4, $rolledUp[F::FOOD]);
        self::assertSame(3, $rolledUp[F::GROCERIES]);

        $this->client->request('GET', '/api/v1/transactions/facets?includeDescendants=false');
        self::assertSame(1, self::section($this->decode(), 'categoryId')[F::FOOD]);
    }

    public function testACheckedCategoryNarrowsTheOtherSectionsButNeverHidesItsSiblings(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets?categoryId='.F::GROCERIES);
        $facets = $this->decode();

        self::assertSame([F::CURRENT => 2, F::SAVINGS => 1], self::section($facets, 'accountId'));
        self::assertSame(['EXPENSE' => 2, 'REFUND' => 1], self::section($facets, 'nature'));
        self::assertSame(['ESSENTIAL' => 3, 'VARIABLE' => 1], self::section($facets, 'axis'));
        self::assertSame(
            [F::FOOD => 1, F::GROCERIES => 3, F::RESTAURANTS => 1, F::TRANSPORT => 1],
            self::sorted(self::section($facets, 'categoryId')),
        );
    }

    public function testVoidedTransactionsAreCountedOnlyWhenShownAndTheStateSectionAlwaysCountsEveryState(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets?includeVoided=true');
        $shown = $this->decode();
        self::assertSame([F::CURRENT => 5, F::SAVINGS => 2], self::section($shown, 'accountId'));
        self::assertSame(1, self::section($shown, 'state')['VOIDED']);

        $this->client->request('GET', '/api/v1/transactions/facets');
        self::assertSame(1, self::section($this->decode(), 'state')['VOIDED']);
    }

    public function testTheToCategoriseQueueNeverCountsVoidedAndLeavesCategoriesAndAxesUncleared(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets?categorization=NONE&includeVoided=true');
        $queue = $this->decode();

        self::assertSame([F::CURRENT => 1], self::section($queue, 'accountId'));
        self::assertSame(['BOOKED' => 1, 'REJECTED' => 1], self::sorted(self::section($queue, 'state')));
        // Checking a category or an axis leaves the queue, so they show the unfiltered numbers.
        self::assertSame(3, self::section($queue, 'categoryId')[F::GROCERIES]);
        self::assertSame(['ESSENTIAL' => 4, 'VARIABLE' => 2], self::sorted(self::section($queue, 'axis')));
    }

    public function testTheOtherWorkspacesOwnerSeesOnlyTheirOwnCounts(): void
    {
        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);

        $this->client->request('GET', '/api/v1/transactions/facets');

        self::assertResponseStatusCodeSame(200);
        $facets = $this->decode();
        self::assertSame([F::FOREIGN_ACCOUNT => 3], self::section($facets, 'accountId'));
        self::assertSame([F::FOREIGN_CATEGORY => 2], self::section($facets, 'categoryId'));
        self::assertSame(['BOOKED' => 2, 'PENDING' => 1], self::sorted(self::section($facets, 'state')));
        self::assertStringNotContainsString(F::CURRENT, (string) $this->client->getResponse()->getContent());
    }

    public function testAnAccountOfAnotherWorkspaceAnswersTheSameNotFoundAsAnUnknownOneAndLeaksNothing(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets?accountId='.F::FOREIGN_ACCOUNT);
        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', '/api/v1/transactions/facets?accountId='.self::UNKNOWN_ACCOUNT);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());

        // Whatever the answer carries, it is nothing of the other workspace.
        foreach ([F::FOREIGN_ACCOUNT, F::FOREIGN_CATEGORY, 'Compte voisin', 'Prive voisin'] as $secret) {
            self::assertStringNotContainsString($secret, $foreign);
        }

        // One owned account does not make a foreign one acceptable.
        $this->client->request('GET', '/api/v1/transactions/facets?accountId='.F::CURRENT.'&accountId='.F::FOREIGN_ACCOUNT);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnArchivedAccountOfTheWorkspaceIsCountedLikeInTheListAndNeverHidesOthers(): void
    {
        $this->connection->executeStatement('UPDATE account_financial_accounts SET archived_at = now() WHERE id = :id', ['id' => F::SAVINGS]);

        $this->client->request('GET', '/api/v1/transactions/facets?accountId='.F::SAVINGS);

        self::assertResponseStatusCodeSame(200);
        $facets = $this->decode();
        self::assertSame([F::CURRENT => 4, F::SAVINGS => 2], self::sorted(self::section($facets, 'accountId')));
        self::assertSame(['EXPENSE' => 1, 'REFUND' => 1], self::sorted(self::section($facets, 'nature')));
    }

    public function testAForeignOrUnknownCategoryMatchesNothingLikeTheList(): void
    {
        foreach ([F::FOREIGN_CATEGORY, self::UNKNOWN_ACCOUNT] as $categoryId) {
            $this->client->request('GET', '/api/v1/transactions/facets?includeDescendants=true&categoryId='.$categoryId);
            self::assertResponseStatusCodeSame(200);
            $facets = $this->decode();
            self::assertSame([], self::section($facets, 'accountId'));
            self::assertSame([], self::section($facets, 'nature'));
        }
    }

    public function testMalformedRequestsAreRefusedWithAProblemAndNothingIsCounted(): void
    {
        $cases = [
            '?pageSize=10', '?cursor=abc', '?unknownField=1', '?accountId=not-a-uuid', '?categoryId=1234', '?state=NOPE',
            '?nature=NOPE', '?source=NOPE', '?axis=NOPE', '?direction=SIDEWAYS', '?categoryType=SAVINGS', '?includeVoided=yes', '?includeDescendants=1',
            '?categorization=SOME', '?from=2026-13-01', '?from=2026-02-01&to=2026-01-01', '?minAmount=-1&assetCode=EUR',
            '?minAmount=10', '?minAmount=ten&assetCode=EUR', '?q='.str_repeat('a', 81), '?assetCode=',
        ];
        foreach ($cases as $query) {
            $this->client->request('GET', '/api/v1/transactions/facets'.$query);
            self::assertResponseStatusCodeSame(400, $query);
            self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'), $query);
        }
    }

    public function testAtMostTwentyValuesPerRepeatedFilter(): void
    {
        $twenty = implode('&', array_map(static fn (int $index): string => sprintf('accountId=00000000-0000-7000-8000-%012x', $index), range(1, 20)));
        $this->client->request('GET', '/api/v1/transactions/facets?'.$twenty);
        // Valid shape, but none of those accounts exists: 404, not a validation error.
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/transactions/facets?'.$twenty.'&accountId='.F::CURRENT);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/transactions/facets?'.implode('&', array_fill(0, 21, 'nature=EXPENSE')));
        self::assertResponseStatusCodeSame(400);
    }

    public function testCountsFollowAWriteTheMomentItIsCommittedAndAVoidTakesTheTransactionBackOut(): void
    {
        $this->client->request('GET', '/api/v1/transactions/facets');
        $before = $this->decode();

        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode([
            'accountId' => F::CURRENT, 'amount' => ['value' => '-12.34', 'assetCode' => 'EUR'], 'nature' => 'EXPENSE', 'state' => 'BOOKED',
            'bookedOn' => '2026-01-30', 'valueOn' => null, 'authorizedOn' => null, 'rawLabel' => 'CB BOULANGERIE', 'counterparty' => null,
            'note' => null, 'paymentMethod' => 'CARD', 'mcc' => null, 'maskedCard' => null, 'bankReference' => null,
            'categoryId' => F::GROCERIES, 'splits' => null,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode();
        self::assertIsString($created['id']);
        self::assertIsInt($created['version']);

        $this->client->request('GET', '/api/v1/transactions/facets');
        $after = $this->decode();
        self::assertSame(self::section($before, 'accountId')[F::CURRENT] + 1, self::section($after, 'accountId')[F::CURRENT]);
        self::assertSame(self::section($before, 'categoryId')[F::GROCERIES] + 1, self::section($after, 'categoryId')[F::GROCERIES]);
        self::assertSame(self::section($before, 'nature')['EXPENSE'] + 1, self::section($after, 'nature')['EXPENSE']);
        self::assertSame(self::section($before, 'source')['MANUAL'] + 1, self::section($after, 'source')['MANUAL']);

        $this->client->request('POST', '/api/v1/transactions/'.$created['id'].'/void', server: self::jsonHeaders(), content: json_encode(['version' => $created['version']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/v1/transactions/facets');
        $voided = $this->decode();
        self::assertSame(self::section($before, 'accountId'), self::section($voided, 'accountId'));
        self::assertSame(self::section($before, 'categoryId'), self::section($voided, 'categoryId'));
        // The state section keeps counting it, as a voided transaction.
        self::assertSame(self::section($before, 'state')['VOIDED'] + 1, self::section($voided, 'state')['VOIDED']);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->client->request('DELETE', '/api/v1/session');
        $this->client->request('GET', '/api/v1/transactions/facets');

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testTheEndpointIsThrottledAfterOneHundredAndTwentyRequestsAMinuteWhileTheSearchIsNot(): void
    {
        $this->client->disableReboot();
        // Refused requests spend the budget too: it is consumed before anything else is read.
        $this->client->request('GET', '/api/v1/transactions/facets?pageSize=1');
        self::assertResponseStatusCodeSame(400, 'request 1');
        $windowStartedNoLaterThan = microtime(true);
        for ($request = 2; $request <= 120; ++$request) {
            $this->client->request('GET', '/api/v1/transactions/facets?pageSize=1');
            self::assertResponseStatusCodeSame(400, 'request '.$request);
        }

        $retryRequestStartedAt = time();
        $this->client->request('GET', '/api/v1/transactions/facets');
        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $retryAfter);
        // Symfony rounds the window's retry timestamp up; HTTP subtracts whole current seconds.
        // A one-minute window can therefore advertise 61 seconds. Bound it by its latest possible
        // end, accounting for the time already spent making the accepted requests.
        $latestRetryAt = (int) ceil($windowStartedNoLaterThan + 60);
        self::assertLessThanOrEqual(max(1, $latestRetryAt - $retryRequestStartedAt), (int) $retryAfter);
        $problem = $this->decode();
        self::assertSame(429, $problem['status']);

        // The search itself stays unthrottled.
        $this->client->request('GET', '/api/v1/transactions');
        self::assertResponseStatusCodeSame(200);
    }

    public function testEachUserHasTheirOwnThrottlingBudget(): void
    {
        $this->client->disableReboot();
        for ($request = 1; $request <= 121; ++$request) {
            $this->client->request('GET', '/api/v1/transactions/facets?pageSize=1');
        }
        self::assertResponseStatusCodeSame(429);

        $this->client->request('DELETE', '/api/v1/session');
        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/transactions/facets');
        self::assertResponseStatusCodeSame(200);
    }

    public function testRollingUpCountsIsRefusedBeyondFiveHundredCategoriesInsteadOfWalkingTheWholeTree(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO category_categories (id, workspace_id, type, label, parent_id, icon, color, default_analytic_axes, budget_included, sort_order, depth, version, created_at, updated_at)
             SELECT gen_random_uuid(), :workspace_id, 'EXPENSE', 'Bulk '||n, NULL, NULL, NULL, '[]', true, 0, 1, 1, now(), now() FROM generate_series(1, 500) AS n",
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE],
        );

        $this->client->request('GET', '/api/v1/transactions/facets');
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/v1/transactions/facets?includeDescendants=true');
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @param array<string, mixed> $facets
     *
     * @return array<string, int>
     */
    private static function section(array $facets, string $name): array
    {
        $section = $facets[$name] ?? null;
        self::assertIsArray($section);

        $typed = [];
        foreach ($section as $key => $count) {
            self::assertIsString($key);
            self::assertIsInt($count);
            $typed[$key] = $count;
        }

        return $typed;
    }

    /**
     * @param array<string, int> $section
     *
     * @return array<string, int>
     */
    private static function sorted(array $section): array
    {
        ksort($section, SORT_STRING);

        return $section;
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
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

    private function resetThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}

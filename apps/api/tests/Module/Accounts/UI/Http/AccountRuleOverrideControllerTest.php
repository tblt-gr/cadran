<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Recording and withdrawing a local claim end to end.
 *
 * What is asserted here is what a screen and a later export may rely on: a
 * claim wins over the publication without hiding it, withdrawing it gives the
 * publication back on every date, and neither can be reached from another
 * workspace.
 */
final class AccountRuleOverrideControllerTest extends WebTestCase
{
    private const string LIVRET_A = '00000000-0000-7000-8000-0000000000e1';
    private const string CTO = '00000000-0000-7000-8000-0000000000e2';
    private const string BY_HAND = '00000000-0000-7000-8000-0000000000e3';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000e5';
    private const string UNKNOWN = '00000000-0000-7000-8000-0000000000ef';

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
        $this->resetLoginThrottling();

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAClaimIsRecordedAttributedAndReadBackInFrontOfThePublication(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $recorded = $this->record(self::LIVRET_A, $this->ceilingBody());
        self::assertResponseStatusCodeSame(201);
        self::assertSame([
            'id', 'accountId', 'kind', 'valueType', 'amount', 'text', 'application', 'brackets',
            'validFrom', 'validTo', 'standing', 'withdrawnAt', 'withdrawnBy',
            'reason', 'authorId', 'authorDisplayName', 'recordedAt',
        ], array_keys($recorded));
        self::assertSame('DEPOSIT_CEILING', $recorded['kind']);
        self::assertSame(['value' => '30000', 'assetCode' => 'EUR'], $recorded['amount']);
        self::assertTrue($recorded['standing']);
        // The author comes from the session, never from the body.
        self::assertSame(WorkspaceFixture::OWNER_ID, $recorded['authorId']);

        $ceiling = $this->onlyCeiling(self::LIVRET_A, '2026-09-02');
        self::assertSame('OVERRIDE', $ceiling['effectiveLayer']);

        $published = $this->fields($ceiling['catalog']);
        self::assertSame('22950', $this->fields($published['amount'])['value']);
        self::assertNotNull($published['source']);
        self::assertNull($published['claim']);

        $claimed = $this->fields($ceiling['override']);
        self::assertSame('30000', $this->fields($claimed['amount'])['value']);
        // A figure the holder typed is never presented as published.
        self::assertNull($claimed['verification']);
        self::assertNull($claimed['source']);
        $claim = $this->fields($claimed['claim']);
        self::assertSame(['overrideId', 'reason', 'authorId', 'authorDisplayName', 'recordedAt'], array_keys($claim));
        self::assertSame('Owner', $claim['authorDisplayName']);
        self::assertSame($recorded['id'], $claim['overrideId']);
        self::assertSame('The branch confirmed a higher ceiling in writing.', $claim['reason']);
    }

    /**
     * Withdrawing is not ending: the publication comes back on every date the
     * claim covered, including past ones.
     */
    public function testWithdrawingGivesThePublicationBackOnEveryDate(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');
        $recorded = $this->record(self::LIVRET_A, $this->ceilingBody());
        self::assertIsString($recorded['id']);

        $withdrawn = $this->withdraw(self::LIVRET_A, $recorded['id']);
        self::assertResponseIsSuccessful();
        self::assertFalse($withdrawn['standing']);
        self::assertSame(WorkspaceFixture::OWNER_ID, $withdrawn['withdrawnBy']);
        self::assertIsString($withdrawn['withdrawnAt']);

        foreach (['2026-03-15', '2026-09-02'] as $date) {
            $ceiling = $this->onlyCeiling(self::LIVRET_A, $date);
            self::assertSame('CATALOG', $ceiling['effectiveLayer'], $date);
            self::assertNull($ceiling['override'], $date);
        }

        // The claim is kept: the trail still shows what was said and by whom.
        $history = $this->history(self::LIVRET_A);
        self::assertCount(1, $history);
        self::assertFalse($this->fields($history[0])['standing']);
    }

    public function testWithdrawingTwiceIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');
        $recorded = $this->record(self::LIVRET_A, $this->ceilingBody());
        self::assertIsString($recorded['id']);

        $this->withdraw(self::LIVRET_A, $recorded['id']);
        $this->requestWithdraw(self::LIVRET_A, $recorded['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(
            'account-rule-override-conflict',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testAStandingClaimCoveringTheSameDaysIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');
        $this->record(self::LIVRET_A, $this->ceilingBody());

        $this->requestRecord(self::LIVRET_A, $this->ceilingBody(validFrom: '2026-06-01'));

        self::assertResponseStatusCodeSame(409);
    }

    /**
     * A rate typed onto a plan whose product promises none would turn an
     * assumption into a promise.
     */
    public function testARateOnAProductThatPromisesNoneIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::CTO, WorkspaceFixture::OWN_WORKSPACE, 'CTO', 'FR_CTO', kind: 'PORTFOLIO');

        $this->requestRecord(self::CTO, [
            'kind' => 'ANNUAL_RATE',
            'amount' => null,
            'amountAssetCode' => null,
            'text' => null,
            'rateApplication' => 'MARGINAL',
            'brackets' => [['lowerBound' => '0', 'upperBound' => null, 'percentage' => '5']],
            'validFrom' => '2026-01-01',
            'validTo' => null,
            'reason' => 'My broker promised 5 %.',
        ]);

        self::assertResponseStatusCodeSame(422);
        // The refusal never echoes the figure back.
        self::assertStringNotContainsString('"5"', (string) $this->client->getResponse()->getContent());
    }

    public function testARateCannotHideNonStringInactiveAmountMembers(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        foreach ([
            ['amount', ['unexpected' => true], '/amount'],
            ['amountAssetCode', 978, '/amountAssetCode'],
        ] as [$field, $literal, $pointer]) {
            $body = [
                'kind' => 'ANNUAL_RATE',
                'amount' => null,
                'amountAssetCode' => null,
                'text' => null,
                'rateApplication' => 'MARGINAL',
                'brackets' => [['lowerBound' => '0', 'upperBound' => null, 'percentage' => '5']],
                'validFrom' => '2026-01-01',
                'validTo' => null,
                'reason' => 'The branch confirmed the rate in writing.',
            ];
            $body[$field] = $literal;

            $this->requestRecord(self::LIVRET_A, $body);

            self::assertResponseStatusCodeSame(422);
            $problem = $this->decode();
            self::assertSame('/problems/amount.not_a_string', $problem['type']);
            self::assertSame($pointer, $problem['pointer']);
            self::assertSame(0, $this->countOverrides());
        }
    }

    public function testAnAccountThatFollowsNothingAcceptsNoClaim(): void
    {
        $this->signIn();
        $this->insertAccount(self::BY_HAND, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');

        $this->requestRecord(self::BY_HAND, $this->ceilingBody());

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnAmountInAnotherUnitThanTheAccountIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $this->requestRecord(self::LIVRET_A, $this->ceilingBody(assetCode: 'USD'));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnknownFieldInTheBodyIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $this->requestRecord(self::LIVRET_A, [...$this->ceilingBody(), 'authorId' => WorkspaceFixture::OTHER_OWNER_ID]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * An account of another workspace answers exactly like one that does not
     * exist, so the refusal never confirms that the identifier is real.
     */
    public function testAnAccountOfAnotherWorkspaceAnswersLikeAnUnknownOne(): void
    {
        $this->signIn();
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Private label', 'FR_LIVRET_A');

        $this->requestRecord(self::FOREIGN, $this->ceilingBody());
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->requestRecord(self::UNKNOWN, $this->ceilingBody());
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/accounts/'.self::FOREIGN.'/rule-overrides');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAnonymousReadIsRefusedBeforeAnyAccountIsRead(): void
    {
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $this->client->request('GET', '/api/v1/accounts/'.self::LIVRET_A.'/rule-overrides');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * An anonymous mutation carries no CSRF token either, so it is stopped one
     * step earlier still. Both refusals happen before the account is read.
     */
    public function testAnAnonymousMutationIsStoppedByTheCsrfGuard(): void
    {
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $this->requestRecord(self::LIVRET_A, $this->ceilingBody());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countOverrides());
    }

    /**
     * Every web mutation is CSRF-protected. A session cookie replayed from
     * another origin must not be enough to record a claim.
     */
    public function testAMutationWithoutACsrfTokenIsRefused(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $this->requestRecord(self::LIVRET_A, $this->ceilingBody());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function ceilingBody(string $validFrom = '2026-01-01', string $assetCode = 'EUR'): array
    {
        return [
            'kind' => 'DEPOSIT_CEILING',
            'amount' => '30000',
            'amountAssetCode' => $assetCode,
            'text' => null,
            'rateApplication' => null,
            'brackets' => [],
            'validFrom' => $validFrom,
            'validTo' => null,
            'reason' => 'The branch confirmed a higher ceiling in writing.',
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function record(string $accountId, array $body): array
    {
        $this->requestRecord($accountId, $body);
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestRecord(string $accountId, array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts/'.rawurlencode($accountId).'/rule-overrides',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function withdraw(string $accountId, string $overrideId): array
    {
        $this->requestWithdraw($accountId, $overrideId);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function requestWithdraw(string $accountId, string $overrideId): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/v1/accounts/%s/rule-overrides/%s/withdraw', rawurlencode($accountId), rawurlencode($overrideId)),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
    }

    /** @return list<mixed> */
    private function history(string $accountId): array
    {
        $this->client->request('GET', '/api/v1/accounts/'.rawurlencode($accountId).'/rule-overrides');
        self::assertResponseIsSuccessful();
        $overrides = $this->decode()['overrides'] ?? null;
        self::assertIsList($overrides);

        return $overrides;
    }

    /** @return array<string, mixed> */
    private function onlyCeiling(string $accountId, string $asOf): array
    {
        $this->client->request('GET', '/api/v1/accounts/'.rawurlencode($accountId).'/rules?asOf='.$asOf);
        self::assertResponseIsSuccessful();
        $ceilings = $this->decode()['ceilings'] ?? null;
        self::assertIsList($ceilings);
        self::assertCount(1, $ceilings);

        return $this->fields($ceilings[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(mixed $row): array
    {
        self::assertIsArray($row);
        $typed = [];
        foreach ($row as $field => $value) {
            self::assertIsString($field);
            $typed[$field] = $value;
        }

        return $typed;
    }

    private function signIn(): void
    {
        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());

        $this->client->request('POST', '/api/v1/session', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    private function insertAccount(
        string $id,
        string $workspaceId,
        string $label,
        ?string $productCode = null,
        string $kind = 'SAVINGS',
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => $kind,
            'product_code' => $productCode,
            'product_model_id' => null,
            'institution' => null,
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $this->fields($decoded);
    }

    private function countOverrides(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM account_rule_overrides');
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}

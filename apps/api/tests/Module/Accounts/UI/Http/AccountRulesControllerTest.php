<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Infrastructure\Persistence\DbalProductModelRepository;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rules an account answers with, read end to end against the seeded
 * catalogue. What is asserted here is what a screen and a later import may
 * rely on: the right period for the date asked for, the measure each ceiling
 * is checked against, and a gap that stays a gap.
 */
final class AccountRulesControllerTest extends WebTestCase
{
    private const string LIVRET_A = '00000000-0000-7000-8000-0000000000e1';
    private const string PEA = '00000000-0000-7000-8000-0000000000e2';
    private const string BY_HAND = '00000000-0000-7000-8000-0000000000e3';
    private const string IN_DOLLARS = '00000000-0000-7000-8000-0000000000e4';
    private const string FOREIGN = '00000000-0000-7000-8000-0000000000e5';
    private const string UNKNOWN = '00000000-0000-7000-8000-0000000000ef';
    private const string CUSTOM_SAVINGS = '00000000-0000-7000-8000-0000000000e6';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalProductModelRepository $models;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->models = new DbalProductModelRepository($connection);
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

    public function testAPassbookAnswersItsCeilingAndItsRateOfTheDay(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $rules = $this->readRules(self::LIVRET_A, '2026-09-02');

        self::assertSame([
            'accountId', 'assetCode', 'productCode', 'productModelId', 'origin', 'asOf',
            'ceilings', 'rates', 'terms', 'unavailableRuleKinds',
        ], array_keys($rules));
        self::assertSame('SYSTEM_CATALOG', $rules['origin']);
        self::assertSame('FR_LIVRET_A', $rules['productCode']);
        self::assertSame('2026-09-02', $rules['asOf']);
        self::assertSame([], $rules['unavailableRuleKinds']);

        $ceiling = $this->only($rules, 'ceilings');
        self::assertSame([
            'kind', 'basis', 'countsCreditedInterest', 'spansSeveralAccounts', 'measurable',
            'amount', 'validFrom', 'validTo', 'verification', 'source',
        ], array_keys($ceiling));
        self::assertSame('DEPOSIT_CEILING', $ceiling['kind']);
        // The amount alone would decide nothing: what makes it usable is that
        // it is checked against deposits and not against the total balance.
        self::assertSame('BALANCE_EXCLUDING_INTEREST', $ceiling['basis']);
        self::assertFalse($ceiling['countsCreditedInterest']);
        self::assertFalse($ceiling['spansSeveralAccounts']);
        self::assertTrue($ceiling['measurable']);
        self::assertSame(['value' => '22950', 'assetCode' => 'EUR'], $ceiling['amount']);
        self::assertSame('2025-04-25', $ceiling['validFrom']);
        // An open end is in force for every later date, not an expiry.
        self::assertNull($ceiling['validTo']);
        self::assertSame('VERIFIED', $ceiling['verification']);
        $source = $this->nested($ceiling, 'source');
        self::assertSame(['publisher', 'title', 'url', 'publishedOn', 'retrievedOn'], array_keys($source));
        self::assertIsString($source['url']);
        self::assertStringStartsWith('https://', $source['url']);

        $rate = $this->only($rules, 'rates');
        self::assertSame([
            'kind', 'guaranteed', 'application', 'brackets',
            'validFrom', 'validTo', 'verification', 'source',
        ], array_keys($rate));
        self::assertSame('ANNUAL_RATE', $rate['kind']);
        self::assertTrue($rate['guaranteed']);
        // A single published rate still resolves to a scale, so a tiered
        // product later needs no second way of reading a rate.
        self::assertSame('MARGINAL', $rate['application']);
        self::assertSame(
            [['percentage' => '1.7', 'lowerBound' => '0', 'upperBound' => null]],
            $rate['brackets'],
        );
        self::assertSame('2026-08-01', $rate['validFrom']);
        self::assertSame('2027-01-31', $rate['validTo']);
    }

    /**
     * The rate is set for a semester and the ceiling is open-ended. Reading a
     * date outside the semester must keep the ceiling and report the rate as
     * unavailable — never as 0 %, which would read as "this passbook earns
     * nothing".
     */
    public function testADateNoRatePeriodCoversReportsTheRateUnavailableRatherThanZero(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        foreach (['2025-06-01', '2030-01-01'] as $outside) {
            $rules = $this->readRules(self::LIVRET_A, $outside);

            self::assertSame([], $rules['rates'], $outside);
            self::assertSame(['ANNUAL_RATE'], $rules['unavailableRuleKinds'], $outside);
            $amount = $this->nested($this->only($rules, 'ceilings'), 'amount');
            self::assertSame('22950', $amount['value'], $outside);
        }
    }

    /**
     * Before the ceiling was recorded, nothing is known. The endpoint says so
     * instead of showing the figure that came later.
     */
    public function testABusinessDateBeforeAnySourcedPeriodResolvesToNothingKnown(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $rules = $this->readRules(self::LIVRET_A, '2020-01-01');

        self::assertSame([], $rules['ceilings']);
        self::assertSame([], $rules['rates']);
        self::assertSame(['DEPOSIT_CEILING', 'ANNUAL_RATE'], $rules['unavailableRuleKinds']);
    }

    /**
     * A plan is capped on what was paid in, and on a second allowance shared
     * with its small-cap sibling. Reading this plan alone can never settle the
     * shared one.
     */
    public function testAPlanSeparatesItsOwnContributionCeilingFromTheSharedOne(): void
    {
        $this->signIn();
        $this->insertAccount(self::PEA, WorkspaceFixture::OWN_WORKSPACE, 'PEA', 'FR_PEA', kind: 'PORTFOLIO');

        $rules = $this->readRules(self::PEA, '2026-09-02');
        $ceilings = $rules['ceilings'];
        self::assertIsList($ceilings);
        self::assertCount(2, $ceilings);

        self::assertSame(
            [
                ['CONTRIBUTION_CEILING', 'CONTRIBUTIONS', false, '150000'],
                ['COMBINED_CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTIONS', true, '225000'],
            ],
            array_map(static function (mixed $ceiling): array {
                self::assertIsArray($ceiling);
                self::assertIsArray($ceiling['amount']);

                return [
                    $ceiling['kind'],
                    $ceiling['basis'],
                    $ceiling['spansSeveralAccounts'],
                    $ceiling['amount']['value'],
                ];
            }, $ceilings),
        );
        // Market value is not what the plan is capped on, so nothing here is
        // measured against a total balance.
        self::assertSame([], array_values(array_filter(
            $ceilings,
            static fn (mixed $ceiling): bool => is_array($ceiling) && true === $ceiling['countsCreditedInterest'],
        )));
        // A plan earns what its assets earn; the catalogue promises no rate.
        self::assertSame([], $rules['rates']);
        self::assertSame([], $rules['unavailableRuleKinds']);
    }

    public function testAnAccountDescribedByHandInheritsNoRuleAndReportsNoGap(): void
    {
        $this->signIn();
        $this->insertAccount(self::BY_HAND, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');

        $rules = $this->readRules(self::BY_HAND, '2026-09-02');

        self::assertSame('NO_PRODUCT', $rules['origin']);
        self::assertNull($rules['productCode']);
        self::assertSame([], $rules['ceilings']);
        self::assertSame([], $rules['rates']);
        self::assertSame([], $rules['terms']);
        self::assertSame([], $rules['unavailableRuleKinds']);
    }

    /**
     * A ceiling published in euros measures nothing on an account denominated
     * elsewhere, and no conversion rate ships with the application. It is
     * reported as not comparable rather than converted or dropped.
     */
    public function testACeilingInAnotherUnitThanTheAccountIsReportedAsNotComparable(): void
    {
        $this->signIn();
        $this->insertAccount(self::IN_DOLLARS, WorkspaceFixture::OWN_WORKSPACE, 'Livret en dollars', 'FR_LIVRET_A', asset: 'USD');

        $ceiling = $this->only($this->readRules(self::IN_DOLLARS, '2026-09-02'), 'ceilings');

        self::assertFalse($ceiling['measurable']);
        self::assertSame('EUR', $this->nested($ceiling, 'amount')['assetCode']);
    }

    /**
     * A model rule carries no publication: grading its freshness or naming a
     * source would fabricate a provenance the model never had.
     */
    public function testAModelBackedAccountAnswersItsOwnRulesWithNoPublication(): void
    {
        $this->signIn();
        $this->persistModel(ProductModelFixture::model(
            workspace: WorkspaceFixture::OWN_WORKSPACE,
            schedule: new ModelRuleSchedule([
                ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '30000', '2025-01-01'),
                ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-01-01'),
            ]),
        ));
        $this->insertAccount(self::CUSTOM_SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Livret Banque X', productModelId: ProductModelFixture::ID);

        $rules = $this->readRules(self::CUSTOM_SAVINGS, '2026-09-02');

        self::assertSame('WORKSPACE_MODEL', $rules['origin']);
        self::assertSame(ProductModelFixture::ID, $rules['productModelId']);
        self::assertNull($rules['productCode']);

        $ceiling = $this->only($rules, 'ceilings');
        self::assertNull($ceiling['verification']);
        self::assertNull($ceiling['source']);

        $rate = $this->only($rules, 'rates');
        self::assertNull($rate['verification']);
        self::assertNull($rate['source']);
    }

    /**
     * Archiving a model stops new accounts from starting on it, but it must
     * never change what an account already backed by it resolves.
     */
    public function testAnArchivedModelStillAnswersForTheAccountItAlreadyBacks(): void
    {
        $this->signIn();
        $model = ProductModelFixture::model(
            workspace: WorkspaceFixture::OWN_WORKSPACE,
            schedule: new ModelRuleSchedule([
                ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
            ]),
        );
        $this->persistModel($model);
        $this->insertAccount(self::CUSTOM_SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Livret Banque X', productModelId: ProductModelFixture::ID);
        $archived = $model->archive(new \DateTimeImmutable('2026-09-04T11:00:00+00:00'));
        self::assertTrue($this->connection->transactional(fn (): bool => $this->models->update($archived, 1)));

        $rules = $this->readRules(self::CUSTOM_SAVINGS, '2026-09-02');
        $rates = $rules['rates'];

        self::assertSame('WORKSPACE_MODEL', $rules['origin']);
        self::assertIsList($rates);
        self::assertCount(1, $rates);
    }

    public function testAnAccountOfAnotherWorkspaceAnswersExactlyLikeAnUnknownOne(): void
    {
        $this->signIn();
        $this->insertAccount(self::FOREIGN, WorkspaceFixture::OTHER_WORKSPACE, 'Private label', 'FR_LIVRET_A');

        $this->request(self::FOREIGN, '2026-09-02');
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->request(self::UNKNOWN, '2026-09-02');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());

        // A path that cannot be an identifier answers the same way, so the
        // shape of the refusal never tells a prober anything.
        $this->request('not-a-uuid', null);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
    }

    public function testAnAnonymousRequestIsRefusedBeforeAnyAccountIsRead(): void
    {
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $this->request(self::LIVRET_A, '2026-09-02');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMalformedBusinessDateIsRefusedWithoutLeakingTheAccount(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        foreach (['02/09/2026', '2026-02-31', '1899-12-31', '2026-09-02T00:00:00Z'] as $malformed) {
            $this->request(self::LIVRET_A, $malformed);
            self::assertResponseStatusCodeSame(400, $malformed);
            self::assertStringNotContainsString('22950', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testTheAnswerIsNeverStoredByAnIntermediary(): void
    {
        $this->signIn();
        $this->insertAccount(self::LIVRET_A, WorkspaceFixture::OWN_WORKSPACE, 'Livret A', 'FR_LIVRET_A');

        $this->request(self::LIVRET_A, '2026-09-02');

        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readRules(string $id, ?string $asOf): array
    {
        $this->request($id, $asOf);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function request(string $id, ?string $asOf): void
    {
        $query = null === $asOf ? '' : '?asOf='.rawurlencode($asOf);
        $this->client->request('GET', '/api/v1/accounts/'.rawurlencode($id).'/rules'.$query);
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private function only(array $rules, string $key): array
    {
        $list = $rules[$key] ?? null;
        self::assertIsList($list);
        self::assertCount(1, $list);
        self::assertIsArray($list[0]);

        return $this->fields($list[0]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function nested(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        self::assertIsArray($value);

        return $this->fields($value);
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function fields(array $row): array
    {
        $typed = [];
        foreach ($row as $field => $value) {
            self::assertIsString($field);
            $typed[$field] = $value;
        }

        return $typed;
    }

    /**
     * The scale-completeness trigger is deferred to commit: a rule and its
     * brackets arrive as separate statements, and are only checked once both
     * have run, exactly as the use case's own transaction boundary does it.
     */
    private function persistModel(ProductModel $model): void
    {
        $this->connection->transactional(function () use ($model): void {
            $this->models->add($model);
        });
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
        string $asset = 'EUR',
        ?string $productModelId = null,
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'asset_code' => $asset,
            'kind' => $kind,
            'product_code' => $productCode,
            'product_model_id' => $productModelId,
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
        self::assertIsArray($decoded);

        return $this->fields($decoded);
    }

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}

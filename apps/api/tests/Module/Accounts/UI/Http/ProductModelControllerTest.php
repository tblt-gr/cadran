<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The endpoint of a workspace's own product models, end to end.
 *
 * Two things are checked here that no unit test can: that a model of another
 * workspace is indistinguishable from one that does not exist, and that a
 * tiered scale travels as canonical decimal strings from the body to the
 * database and back without a binary float in between.
 */
final class ProductModelControllerTest extends WebTestCase
{
    private const string FOREIGN_ID = '00000000-0000-7000-8000-0000000000df';
    private const string UNKNOWN_ID = '00000000-0000-7000-8000-0000000000de';

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

    public function testTheOwnerRecordsAModelWithATieredRateAndAnOpenEndedCeiling(): void
    {
        $model = $this->createModel();

        // The published field set, asserted whole: a view field added without a
        // matching schema change would otherwise reach clients unnoticed.
        self::assertSame([
            'id', 'name', 'family', 'nature', 'wrapperKind', 'yieldKind', 'yieldGuaranteed',
            'ceilingBasis', 'defaultGroupCode', 'valuationMode', 'capabilities', 'origin',
            'basedOnProductCode', 'basedOnModelId', 'rules', 'editable', 'version',
            'createdAt', 'updatedAt', 'archivedAt',
        ], array_keys($model));
        self::assertSame('ASSET', $model['nature']);
        self::assertSame('DECLARED', $model['origin']);
        self::assertTrue($model['yieldGuaranteed']);
        self::assertSame(1, $model['version']);

        $rules = $this->rulesOf($model);
        self::assertSame(['BALANCE_CEILING', 'ANNUAL_RATE'], array_column($rules, 'kind'));

        $ceiling = $rules[0];
        self::assertSame(['value' => '30000', 'assetCode' => 'EUR'], $ceiling['amount']);
        // An open end is in force with no known end; it is never an expiry and
        // never a zero.
        self::assertNull($ceiling['validTo']);

        $rate = $rules[1];
        self::assertSame('MARGINAL', $rate['rateApplication']);
        self::assertSame([
            ['lowerBound' => '0', 'upperBound' => '10000', 'percentage' => '4'],
            ['lowerBound' => '10000', 'upperBound' => null, 'percentage' => '2'],
        ], $rate['brackets']);
    }

    public function testATieredScaleWithAGapIsRefusedAndNothingIsPersisted(): void
    {
        $body = $this->payload();
        $body['rules'] = [$this->ratePeriod('2026-01-01', [
            ['lowerBound' => '0', 'upperBound' => '10000', 'percentage' => '4'],
            ['lowerBound' => '20000', 'upperBound' => null, 'percentage' => '2'],
        ])];

        $this->request('POST', '/api/v1/product-models', $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownModelCount());
    }

    public function testATieredScaleThatDoesNotStartAtZeroOrLeavesNoOpenBracketIsRefused(): void
    {
        foreach ([
            [['lowerBound' => '100', 'upperBound' => null, 'percentage' => '4']],
            [['lowerBound' => '0', 'upperBound' => '10000', 'percentage' => '4']],
            [
                ['lowerBound' => '0', 'upperBound' => '10000', 'percentage' => '4'],
                ['lowerBound' => '5000', 'upperBound' => null, 'percentage' => '2'],
            ],
        ] as $brackets) {
            $body = $this->payload();
            $body['rules'] = [$this->ratePeriod('2026-01-01', $brackets)];
            $this->request('POST', '/api/v1/product-models', $body);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertSame(0, $this->ownModelCount());
    }

    public function testAMarketModelCannotCarryARatePeriod(): void
    {
        $body = $this->payload();
        $body['yieldKind'] = 'MARKET';
        $body['rules'] = [$this->ratePeriod('2026-01-01')];

        $this->request('POST', '/api/v1/product-models', $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownModelCount());
    }

    public function testRecordingAPeriodAppendsItAndClosesTheOneItSupersedes(): void
    {
        $model = $this->createModel();
        $id = self::stringValue($model, 'id');

        $this->request('POST', '/api/v1/product-models/'.$id.'/rules', [
            ...$this->ratePeriod('2027-01-01', [
                ['lowerBound' => '0', 'upperBound' => null, 'percentage' => '1.5'],
            ]),
            'version' => self::intValue($model, 'version'),
        ]);
        self::assertResponseIsSuccessful();

        $revised = $this->decode();
        self::assertSame(2, $revised['version']);
        $rates = array_values(array_filter($this->rulesOf($revised), static fn (array $rule): bool => 'ANNUAL_RATE' === $rule['kind']));
        self::assertCount(2, $rates);
        self::assertSame('2026-12-31', $rates[0]['validTo']);
        self::assertNull($rates[1]['validTo']);
        self::assertSame('1.5', $rates[1]['brackets'][0]['percentage']);
    }

    public function testAPeriodContradictingARecordedOneIsRefusedWithoutChangingTheModel(): void
    {
        $model = $this->createModel();
        $id = self::stringValue($model, 'id');

        // Two rates starting the same day, or a new one starting before the
        // period it would have to close, both give one date two answers. They
        // are refused rather than reconciled on the holder's behalf.
        foreach (['2026-01-01', '2025-01-01'] as $validFrom) {
            $this->request('POST', '/api/v1/product-models/'.$id.'/rules', [
                ...$this->ratePeriod($validFrom),
                'version' => self::intValue($model, 'version'),
            ]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->request('GET', '/api/v1/product-models/'.$id);
        self::assertSame(1, $this->decode()['version']);
    }

    public function testRecordingAPeriodUsesOptimisticVersioning(): void
    {
        $model = $this->createModel();
        $id = self::stringValue($model, 'id');

        $this->request('POST', '/api/v1/product-models/'.$id.'/rules', [
            ...$this->ratePeriod('2027-01-01'),
            'version' => 7,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type'] ?? null);
    }

    public function testAModelIsDuplicatedWithItsPeriodsAndWithoutItsIdentity(): void
    {
        $model = $this->createModel();
        $id = self::stringValue($model, 'id');

        $this->request('POST', '/api/v1/product-models/'.$id.'/duplicate', ['name' => 'Livret Banque X (copie)']);
        self::assertResponseStatusCodeSame(201);

        $copy = $this->decode();
        self::assertNotSame($id, $copy['id']);
        self::assertSame('WORKSPACE_MODEL', $copy['origin']);
        self::assertSame($id, $copy['basedOnModelId']);
        self::assertSame(1, $copy['version']);

        // The effective dates are the source's, unchanged, and every period
        // carries a fresh identifier of its own.
        $source = $this->rulesOf($model);
        $copied = $this->rulesOf($copy);
        self::assertSame(array_column($source, 'validFrom'), array_column($copied, 'validFrom'));
        self::assertSame(array_column($source, 'validTo'), array_column($copied, 'validTo'));
        self::assertSame([], array_intersect(array_column($source, 'id'), array_column($copied, 'id')));
    }

    public function testAModelStartedFromACatalogueProductCarriesWhatTheCatalogueSays(): void
    {
        $this->request('POST', '/api/v1/product-models/from-product', [
            'name' => 'Livret A Banque X',
            'productCode' => 'FR_LIVRET_A',
            'valuationMode' => 'TRANSACTIONS',
        ]);
        self::assertResponseStatusCodeSame(201);

        $model = $this->decode();
        self::assertSame('SYSTEM_PRODUCT', $model['origin']);
        self::assertSame('FR_LIVRET_A', $model['basedOnProductCode']);
        self::assertSame('SAVINGS', $model['family']);
        self::assertNotSame([], $this->rulesOf($model));

        // A published rate resolves to the one-bracket scale every workspace
        // rate travels in.
        $rates = array_values(array_filter($this->rulesOf($model), static fn (array $rule): bool => 'ANNUAL_RATE' === $rule['kind']));
        self::assertNotSame([], $rates);
        self::assertSame('0', $rates[0]['brackets'][0]['lowerBound']);
        self::assertNull($rates[0]['brackets'][0]['upperBound']);
    }

    public function testAnUnknownCatalogueProductCannotBeClaimedAsASource(): void
    {
        $this->request('POST', '/api/v1/product-models/from-product', [
            'name' => 'Livret inventé',
            'productCode' => 'FR_NOT_A_PRODUCT',
            'valuationMode' => 'TRANSACTIONS',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownModelCount());
    }

    public function testArchivingRemovesAModelFromTheWorkingSetAndKeepsItReadable(): void
    {
        $model = $this->createModel();
        $id = self::stringValue($model, 'id');

        $this->request('POST', '/api/v1/product-models/'.$id.'/archive', ['version' => self::intValue($model, 'version')]);
        self::assertResponseIsSuccessful();
        $archived = $this->decode();
        self::assertFalse($archived['editable']);
        self::assertNotNull($archived['archivedAt']);

        $this->client->request('GET', '/api/v1/product-models');
        self::assertSame([], $this->items());
        $this->client->request('GET', '/api/v1/product-models?includeArchived=true');
        self::assertCount(1, $this->items());

        // Still readable by identifier, with everything it ever said.
        $this->client->request('GET', '/api/v1/product-models/'.$id);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->rulesOf($this->decode()));

        // And accepting nothing further.
        $this->request('POST', '/api/v1/product-models/'.$id.'/rules', [
            ...$this->ratePeriod('2027-01-01'),
            'version' => self::intValue($archived, 'version'),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/product-model-archived', $this->decode()['type'] ?? null);

        $this->request('POST', '/api/v1/product-models/'.$id.'/duplicate', ['name' => 'Reprise']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnActiveNameIsTakenOnceAndAnswersItsOwnProblemType(): void
    {
        $this->createModel();

        $this->request('POST', '/api/v1/product-models', $this->payload());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/product-model-name-taken', $this->decode()['type'] ?? null);
    }

    public function testAForeignModelAnswersExactlyLikeAnUnknownOne(): void
    {
        $this->insertForeignModel();

        foreach ([self::FOREIGN_ID, self::UNKNOWN_ID] as $index => $id) {
            $this->client->request('GET', '/api/v1/product-models/'.$id);
            self::assertResponseStatusCodeSame(404);
            $bodies[$index] = (string) $this->client->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);

        $this->request('POST', '/api/v1/product-models/'.self::FOREIGN_ID.'/archive', ['version' => 1]);
        self::assertResponseStatusCodeSame(404);

        $this->request('POST', '/api/v1/product-models/'.self::FOREIGN_ID.'/duplicate', ['name' => 'Vol']);
        self::assertResponseStatusCodeSame(404);

        $this->request('POST', '/api/v1/product-models/'.self::FOREIGN_ID.'/rules', [
            ...$this->ratePeriod('2027-01-01'),
            'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/product-models?includeArchived=true');
        self::assertSame([], $this->items());

        // Nothing of the other workspace was touched by any of those calls.
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM account_product_models WHERE workspace_id = ?',
            [WorkspaceFixture::OTHER_WORKSPACE],
        ));
    }

    public function testMassAssignmentAndUnknownFieldsAreRefused(): void
    {
        foreach ([
            ['workspaceId' => WorkspaceFixture::OTHER_WORKSPACE],
            ['version' => 5],
            ['origin' => 'SYSTEM_PRODUCT'],
            ['basedOnProductCode' => 'FR_LIVRET_A'],
        ] as $extra) {
            $this->request('POST', '/api/v1/product-models', [...$this->payload(), ...$extra]);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertSame(0, $this->ownModelCount());
    }

    public function testARateSentAsAJsonNumberIsRefusedRatherThanRounded(): void
    {
        $body = $this->payload();
        $body['rules'] = [[
            ...$this->ratePeriod('2026-01-01'),
            'brackets' => [['lowerBound' => 0, 'upperBound' => null, 'percentage' => 1.7]],
        ]];

        $this->request('POST', '/api/v1/product-models', $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownModelCount());
    }

    public function testAnAnonymousCallerReachesNothing(): void
    {
        $this->client->request('DELETE', '/api/v1/session');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/product-models');
        self::assertResponseStatusCodeSame(401);

        $this->request('POST', '/api/v1/product-models', $this->payload());
        self::assertResponseStatusCodeSame(401);
    }

    public function testAMutationWithoutACsrfTokenIsRefused(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'forged');
        $this->request('POST', '/api/v1/product-models', $this->payload());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->ownModelCount());
    }

    /** @return array<string, mixed> */
    private function createModel(): array
    {
        $this->request('POST', '/api/v1/product-models', $this->payload());
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'Livret Banque X',
            'family' => 'SAVINGS',
            'wrapperKind' => 'NONE',
            'yieldKind' => 'CONTRACTUAL_FIXED',
            'defaultGroupCode' => 'SAVINGS',
            'valuationMode' => 'TRANSACTIONS',
            'capabilities' => ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'],
            'rules' => [
                [
                    'kind' => 'BALANCE_CEILING',
                    'amount' => '30000',
                    'amountAssetCode' => 'EUR',
                    'text' => null,
                    'rateApplication' => null,
                    'brackets' => [],
                    'validFrom' => '2026-01-01',
                    'validTo' => null,
                ],
                $this->ratePeriod('2026-01-01'),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $brackets
     *
     * @return array<string, mixed>
     */
    private function ratePeriod(string $validFrom, ?array $brackets = null): array
    {
        return [
            'kind' => 'ANNUAL_RATE',
            'amount' => null,
            'amountAssetCode' => null,
            'text' => null,
            'rateApplication' => 'MARGINAL',
            'brackets' => $brackets ?? [
                ['lowerBound' => '0', 'upperBound' => '10000', 'percentage' => '4'],
                ['lowerBound' => '10000', 'upperBound' => null, 'percentage' => '2'],
            ],
            'validFrom' => $validFrom,
            'validTo' => null,
        ];
    }

    private function insertForeignModel(): void
    {
        $this->connection->insert('account_product_models', [
            'id' => self::FOREIGN_ID,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'name' => 'Modèle privé',
            'family' => 'SAVINGS',
            'wrapper_kind' => 'NONE',
            'yield_kind' => 'CONTRACTUAL_FIXED',
            'default_group_code' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'origin' => 'DECLARED',
            'derived_from_product_code' => null,
            'derived_from_model_id' => null,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);
        $this->connection->insert('account_product_model_capabilities', [
            'model_id' => self::FOREIGN_ID,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'capability_code' => 'SUPPORTS_BALANCE',
        ]);
        $this->connection->insert('account_product_model_capabilities', [
            'model_id' => self::FOREIGN_ID,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'capability_code' => 'SUPPORTS_TRANSACTIONS',
        ]);
    }

    /**
     * @param array<string, mixed> $model
     *
     * @return list<array<string, mixed>>
     */
    private function rulesOf(array $model): array
    {
        $rules = $model['rules'] ?? null;
        self::assertIsList($rules);

        return array_map(static function (mixed $rule): array {
            self::assertIsArray($rule);

            $typed = [];
            foreach ($rule as $key => $value) {
                self::assertIsString($key);
                $typed[$key] = $value;
            }

            return $typed;
        }, $rules);
    }

    private function ownModelCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM account_product_models WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, array $body): void
    {
        $this->client->request(
            $method,
            $uri,
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
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

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $items = $this->decode()['items'] ?? null;
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

    /** @param array<string, mixed> $data */
    private static function stringValue(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsString($data[$key]);

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function intValue(array $data, string $key): int
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsInt($data[$key]);

        return $data[$key];
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function resetLoginThrottling(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}

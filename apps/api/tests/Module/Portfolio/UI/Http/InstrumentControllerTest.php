<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InstrumentControllerTest extends WebTestCase
{
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
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTypeFilterRunsBeforePaginationAndRetainsWorkspaceIsolationAndBounds(): void
    {
        $fixtures = new \App\Tests\Support\PortfolioFixture($this->connection);
        for ($index = 1; $index <= 25; ++$index) {
            $fixtures->instrument(sprintf('00000000-0000-7000-8000-%012d', 1000 + $index), name: 'AAA Security '.$index);
        }
        $first = '00000000-0000-7000-8000-000000009001';
        $second = '00000000-0000-7000-8000-000000009002';
        $fixtures->euroFund($first);
        $fixtures->euroFund($second);
        $fixtures->euroFund('00000000-0000-7000-8000-000000009003', WorkspaceFixture::OTHER_WORKSPACE);
        $this->client->request('GET', '/api/v1/instruments?instrumentType=EURO_FUND&perPage=1&page=1');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['total']);
        self::assertSame([$first], array_column(self::items($this->decode()), 'id'));
        $this->client->request('GET', '/api/v1/instruments?instrumentType=EURO_FUND&perPage=1&page=2');
        self::assertResponseIsSuccessful();
        self::assertSame([$second], array_column(self::items($this->decode()), 'id'));
        foreach (['instrumentType=INVALID', 'instrumentType=', 'instrumentType=EURO_FUND&perPage=51', 'instrumentType=EURO_FUND&page=1001'] as $query) {
            $this->client->request('GET', '/api/v1/instruments?'.$query);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_instrument_query', $this->decode()['code']);
        }
    }

    public function testTheOwnerCreatesReadsListsEditsAndChangesInstrumentStatus(): void
    {
        $created = $this->create();
        self::assertSame([
            'id', 'name', 'isin', 'ticker', 'venueMic', 'instrumentType', 'assetClass', 'region',
            'currencyCode', 'quantityPrecision', 'pricePrecision', 'source', 'reviewed', 'reviewedAt',
            'status', 'used', 'version',
        ], array_keys($created));
        self::assertSame('FR0000120271', $created['isin']);
        self::assertSame('WLD', $created['ticker']);
        self::assertSame(6, $created['quantityPrecision']);
        self::assertSame(4, $created['pricePrecision']);
        self::assertTrue($created['reviewed']);
        self::assertSame('MANUAL', $created['source']);

        $id = self::string($created['id']);
        $this->client->request('GET', '/api/v1/instruments/'.$id);
        self::assertResponseIsSuccessful();

        $this->request('PUT', '/api/v1/instruments/'.$id, [...$this->writeBody($created), 'name' => 'ETF Monde renommé', 'version' => 1]);
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        self::assertSame('ETF Monde renommé', $edited['name']);
        self::assertSame(2, $edited['version']);

        $this->request('POST', '/api/v1/instruments/'.$id.'/deactivate', ['version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame('INACTIVE', $this->decode()['status']);
        $this->client->request('GET', '/api/v1/instruments');
        self::assertSame([], $this->decode()['items']);
        $this->client->request('GET', '/api/v1/instruments?status=inactive');
        self::assertSame([$id], array_column(self::items($this->decode()), 'id'));

        $this->request('POST', '/api/v1/instruments/'.$id.'/reactivate', ['version' => 3]);
        self::assertResponseIsSuccessful();
        self::assertSame('ACTIVE', $this->decode()['status']);
        self::assertSame(4, $this->decode()['version']);

        self::assertSame([
            'instrument.created', 'instrument.updated', 'instrument.deactivated', 'instrument.reactivated',
        ], $this->connection->fetchFirstColumn(
            "SELECT event_type FROM audit_events WHERE workspace_id = ? AND entity_type = 'portfolio_instrument' ORDER BY occurred_at, id",
            [WorkspaceFixture::OWN_WORKSPACE],
        ));
        $updateDiff = $this->connection->fetchOne(
            "SELECT before_json FROM audit_events WHERE workspace_id = ? AND event_type = 'instrument.updated'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsString($updateDiff);
        self::assertSame(['name' => 'unchanged'], json_decode($updateDiff, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testInvalidIdentifiersAndDuplicateFlowsExposeStableProblemCodes(): void
    {
        $this->request('POST', '/api/v1/instruments', $this->payload(['name' => "\u{00A0}\u{202F}"]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_instrument_name', $this->decode()['code']);

        $this->request('POST', '/api/v1/instruments', $this->payload(['isin' => 'FR0000120272']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_instrument_isin', $this->decode()['code']);

        $existing = $this->create();
        $this->request('POST', '/api/v1/instruments/'.self::string($existing['id']).'/deactivate', ['version' => 1]);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/api/v1/instruments', $this->payload(['name' => 'Different', 'ticker' => 'OTHER']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('instrument_isin_exists', $this->decode()['code']);
        self::assertSame($existing['id'], $this->decode()['existingId']);

        $this->request('POST', '/api/v1/instruments', $this->payload([
            'name' => 'ETF   Monde', 'isin' => 'US0378331005', 'ticker' => 'OTHER',
        ]));
        self::assertResponseStatusCodeSame(409);
        $problem = $this->decode();
        self::assertSame('instrument_duplicate_candidates', $problem['code']);
        self::assertCount(1, self::items(['items' => $problem['candidates']]));

        $this->request('POST', '/api/v1/instruments', $this->payload([
            'name' => 'ETF   Monde', 'isin' => 'US0378331005', 'ticker' => 'OTHER', 'confirmDuplicate' => true,
        ]));
        self::assertResponseStatusCodeSame(201);

        $this->request('POST', '/api/v1/instruments', $this->payload(['isin' => 'IE00B4L5Y983', 'currencyCode' => 'BTC']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported_instrument_currency', $this->decode()['code']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidFieldProvider(): iterable
    {
        yield 'ticker with a space' => [['ticker' => 'WL D'], 'invalid_instrument_ticker'];
        yield 'ticker too long' => [['ticker' => 'ABCDEFGHIJKLM'], 'invalid_instrument_ticker'];
        yield 'ticker starting with a dot' => [['ticker' => '.WLD'], 'invalid_instrument_ticker'];
        yield 'venue too short' => [['venueMic' => 'XPA'], 'invalid_instrument_venue'];
        yield 'venue with a symbol' => [['venueMic' => 'XP-R'], 'invalid_instrument_venue'];
        yield 'unknown type' => [['instrumentType' => 'CRYPTO'], 'invalid_instrument_type'];
        yield 'lowercase type' => [['instrumentType' => 'etf'], 'invalid_instrument_type'];
        yield 'unknown class' => [['assetClass' => 'GOLD'], 'invalid_instrument_classification'];
        yield 'unknown region' => [['region' => 'MARS'], 'invalid_instrument_classification'];
        yield 'quantity precision above twelve' => [['quantityPrecision' => 13], 'invalid_instrument_precision'];
        yield 'negative price precision' => [['pricePrecision' => -1], 'invalid_instrument_precision'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidFieldProvider')]
    public function testAnInvalidFieldIsRefusedWithItsStableCodeAndWritesNothing(array $override, string $code): void
    {
        $this->request('POST', '/api/v1/instruments', $this->payload($override));

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->decode()['code']);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM portfolio_instruments'));
    }

    public function testAnInvalidFieldOnEditIsRefusedWithItsStableCode(): void
    {
        $created = $this->create();

        $this->request('PUT', '/api/v1/instruments/'.self::string($created['id']), [
            ...$this->writeBody($created), 'ticker' => 'WL D', 'version' => 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_instrument_ticker', $this->decode()['code']);
    }

    public function testVersionLockBoundsCsrfAndWorkspaceIsolationAreEnforced(): void
    {
        $created = $this->create();
        $id = self::string($created['id']);
        $this->connection->update('portfolio_instruments', ['used_at' => '2026-09-01 12:00:00+00'], ['id' => $id]);

        $this->request('PUT', '/api/v1/instruments/'.$id, [...$this->writeBody($created), 'currencyCode' => 'USD', 'version' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('instrument_identity_locked', $this->decode()['code']);

        $this->request('PUT', '/api/v1/instruments/'.$id, [...$this->writeBody($created), 'name' => 'Allowed rename', 'version' => 1]);
        self::assertResponseIsSuccessful();
        $this->request('PUT', '/api/v1/instruments/'.$id, [...$this->writeBody($created), 'name' => 'Stale rename', 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_instrument_version', $this->decode()['code']);

        $this->client->request('GET', '/api/v1/instruments?q='.str_repeat('x', 61));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_instrument_query', $this->decode()['code']);

        $foreignId = '00000000-0000-7000-8000-0000000001ff';
        $this->insertForeign($foreignId);
        $this->client->request('GET', '/api/v1/instruments/'.$foreignId);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/instruments?status=all');
        self::assertNotContains($foreignId, array_column(self::items($this->decode()), 'id'));
        $this->request('PUT', '/api/v1/instruments/'.$foreignId, [...$this->writeBody($created), 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/v1/instruments/'.$foreignId.'/deactivate', ['version' => 1]);
        self::assertResponseStatusCodeSame(404);

        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'invalid');
        $this->request('POST', '/api/v1/instruments', $this->payload(['isin' => 'IE00B4L5Y983']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/csrf-token', $this->decode()['type']);
    }

    public function testTheFiveHundredAndFirstInstrumentIsRefused(): void
    {
        for ($index = 0; $index < 500; ++$index) {
            $id = sprintf('00000000-0000-7000-8%03x-%012x', $index, $index + 1);
            $this->insertInstrument($id, WorkspaceFixture::OWN_WORKSPACE, 'Instrument '.$index, 'T'.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }

        $this->request('POST', '/api/v1/instruments', $this->payload());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('instrument_limit_reached', $this->decode()['code']);
    }

    public function testAnEditDetectsAnotherInstrumentAsADuplicateCandidate(): void
    {
        $first = $this->create();
        $second = $this->create([
            'name' => 'Action France', 'isin' => 'US0378331005', 'ticker' => 'FRANCE',
        ]);

        $this->request('PUT', '/api/v1/instruments/'.self::string($second['id']), [
            ...$this->writeBody($second), 'ticker' => $first['ticker'], 'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('instrument_duplicate_candidates', $this->decode()['code']);
    }

    public function testTheListFiltersByCurrencyInsideTheCallerWorkspace(): void
    {
        $euro = $this->create();
        $dollar = $this->create(['name' => 'Apple', 'isin' => 'US0378331005', 'ticker' => 'AAPL', 'currencyCode' => 'USD']);
        $this->insertForeign('00000000-0000-7000-8000-0000000001ff');

        $this->client->request('GET', '/api/v1/instruments?status=all&currencyCode=USD');
        self::assertResponseIsSuccessful();
        self::assertSame([$dollar['id']], array_column(self::items($this->decode()), 'id'));
        self::assertSame(1, $this->decode()['total']);

        $this->client->request('GET', '/api/v1/instruments?status=all&currencyCode=EUR');
        self::assertSame([$euro['id']], array_column(self::items($this->decode()), 'id'));

        foreach (['usd', 'E', 'EUR-1', str_repeat('A', 13)] as $invalid) {
            $this->client->request('GET', '/api/v1/instruments?currencyCode='.rawurlencode($invalid));
            self::assertResponseStatusCodeSame(422, $invalid);
            self::assertSame('invalid_instrument_query', $this->decode()['code']);
        }
    }

    public function testAnInvalidNameIsRefusedBeforeDuplicateCandidatesOnCreateAndEdit(): void
    {
        $first = $this->create();
        $tooLong = str_repeat('x', 121);

        $this->request('POST', '/api/v1/instruments', $this->payload(['name' => $tooLong, 'isin' => 'US0378331005']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_instrument_name', $this->decode()['code']);

        $second = $this->create(['name' => 'Action France', 'isin' => 'US0378331005', 'ticker' => 'FRANCE']);
        $this->request('PUT', '/api/v1/instruments/'.self::string($second['id']), [
            ...$this->writeBody($second), 'name' => $tooLong, 'ticker' => $first['ticker'], 'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_instrument_name', $this->decode()['code']);
    }

    public function testAnEditCollidingOnAnotherIsinIsAConflict(): void
    {
        $first = $this->create();
        $second = $this->create(['name' => 'Action France', 'isin' => 'US0378331005', 'ticker' => 'FRANCE']);

        $this->request('PUT', '/api/v1/instruments/'.self::string($second['id']), [
            ...$this->writeBody($second), 'isin' => $first['isin'], 'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('instrument_isin_exists', $this->decode()['code']);
        self::assertSame($first['id'], $this->decode()['existingId']);
    }

    public function testRepeatingAStatusChangeIsAConflict(): void
    {
        $id = self::string($this->create()['id']);

        $this->request('POST', '/api/v1/instruments/'.$id.'/reactivate', ['version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('instrument_already_active', $this->decode()['code']);

        $this->request('POST', '/api/v1/instruments/'.$id.'/deactivate', ['version' => 1]);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/api/v1/instruments/'.$id.'/deactivate', ['version' => 2]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('instrument_already_inactive', $this->decode()['code']);
    }

    private function signIn(): void
    {
        $this->request('POST', '/api/v1/session', [
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function create(array $overrides = []): array
    {
        $this->request('POST', '/api/v1/instruments', $this->payload($overrides));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'ETF Monde', 'isin' => ' fr0000120271 ', 'ticker' => 'wld', 'venueMic' => 'xpar',
            'instrumentType' => 'ETF', 'assetClass' => 'EQUITY', 'region' => 'WORLD', 'currencyCode' => 'EUR',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $instrument
     *
     * @return array<string, mixed>
     */
    private function writeBody(array $instrument): array
    {
        return array_intersect_key($instrument, array_flip([
            'name', 'isin', 'ticker', 'venueMic', 'instrumentType', 'assetClass', 'region', 'currencyCode',
            'quantityPrecision', 'pricePrecision',
        ]));
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
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

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function insertForeign(string $id): void
    {
        $this->insertInstrument($id, WorkspaceFixture::OTHER_WORKSPACE, 'Private instrument', null, 'IE00B4L5Y983');
    }

    private function insertInstrument(string $id, string $workspaceId, string $name, ?string $ticker, ?string $isin = null): void
    {
        $this->connection->insert('portfolio_instruments', [
            'id' => $id, 'workspace_id' => $workspaceId, 'name' => $name,
            'normalized_name' => mb_strtolower($name), 'isin' => $isin, 'ticker' => $ticker, 'venue_mic' => null,
            'instrument_type' => 'FUND', 'asset_class' => null, 'region' => null, 'currency_code' => 'EUR',
            'quantity_precision' => 6, 'price_precision' => 4, 'source' => 'MANUAL',
            'reviewed_at' => '2026-09-01 12:00:00+00',
            'reviewed_by' => WorkspaceFixture::OTHER_WORKSPACE === $workspaceId ? WorkspaceFixture::OTHER_OWNER_ID : WorkspaceFixture::OWNER_ID,
            'status' => 'ACTIVE', 'used_at' => null, 'created_at' => '2026-09-01 12:00:00+00',
            'created_by' => WorkspaceFixture::OTHER_WORKSPACE === $workspaceId ? WorkspaceFixture::OTHER_OWNER_ID : WorkspaceFixture::OWNER_ID,
            'updated_at' => '2026-09-01 12:00:00+00', 'version' => 1,
        ]);
    }
}

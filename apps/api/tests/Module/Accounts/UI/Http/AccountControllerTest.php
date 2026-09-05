<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Infrastructure\Persistence\DbalProductModelRepository;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountControllerTest extends WebTestCase
{
    private const string OTHER_MODEL = '00000000-0000-7000-8000-0000000000e2';

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

    public function testTheOwnerCreatesEditsClosesAndArchivesAnAccount(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');

        // The published field set, asserted whole: a view field added without a
        // matching schema change would otherwise reach clients unnoticed.
        self::assertSame([
            'id', 'label', 'assetCode', 'kind', 'productCode', 'productModelId', 'institution', 'maskedIdentifier',
            'valuationMode', 'liquidityLevel', 'includeInNetWorth', 'includeInEmergencyFund',
            'openedOn', 'closedOn', 'status', 'netWorthSign', 'used', 'editable', 'kindEditable',
            'kindEditReason', 'version', 'archivedAt', 'primaryGroupId', 'tagGroupIds', 'share', 'valuation',
        ], array_keys($account));
        self::assertSame('EUR', $account['assetCode']);
        self::assertSame('SAVINGS', $account['kind']);
        self::assertSame('ACTIVE', $account['status']);
        self::assertSame(1, $account['netWorthSign']);
        self::assertTrue($account['kindEditable']);
        self::assertNull($account['closedOn']);
        self::assertNull($account['primaryGroupId']);
        self::assertSame([], $account['tagGroupIds']);
        self::assertSame([
            'ratio' => null,
            'percent' => null,
            'percentDisplay' => null,
            'reason' => 'MISSING_VALUATION',
        ], $account['share']);
        $createdValuation = $account['valuation'];
        self::assertIsArray($createdValuation);
        self::assertSame('MISSING', $createdValuation['quality']);
        self::assertNull($createdValuation['amount']);
        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
            $createdValuation['requestedOn'],
        );

        $this->requestUpdate($id, [...$account, 'label' => 'Livret A Banque Y', 'liquidityLevel' => 'SHORT_TERM']);
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        self::assertSame('Livret A Banque Y', $edited['label']);
        self::assertSame('SHORT_TERM', $edited['liquidityLevel']);
        self::assertSame(2, $edited['version']);

        $this->requestUpdate($id, [...$edited, 'closedOn' => '2026-02-01']);
        self::assertResponseIsSuccessful();
        $closed = $this->decode();
        self::assertSame('CLOSED', $closed['status']);
        self::assertSame('2026-02-01', $closed['closedOn']);

        $this->requestArchive($id, self::intValue($closed, 'version'));
        self::assertResponseIsSuccessful();
        $archived = $this->decode();
        self::assertSame('ARCHIVED', $archived['status']);
        self::assertFalse($archived['editable']);
        self::assertNotNull($archived['archivedAt']);
    }

    public function testALiabilityIsSignedNegativelyForNetWorth(): void
    {
        $loan = $this->createAccount(label: 'Prêt immobilier', overrides: [
            'kind' => 'LIABILITY',
            'liquidityLevel' => 'ILLIQUID',
            'valuationMode' => 'SNAPSHOTS',
        ]);

        self::assertSame(-1, $loan['netWorthSign']);
    }

    public function testClosedAndArchivedAccountsAreRequestedExplicitly(): void
    {
        $open = $this->createAccount(label: 'Compte courant', overrides: ['kind' => 'CURRENT']);
        $closed = $this->createAccount(label: 'Ancien livret', overrides: ['closedOn' => '2026-02-01']);

        $this->client->request('GET', '/api/v1/accounts');
        self::assertSame(['Compte courant'], array_column($this->items(), 'label'));

        $this->client->request('GET', '/api/v1/accounts?includeClosed=true');
        self::assertCount(2, $this->items());

        $this->requestArchive(self::stringValue($closed, 'id'), self::intValue($closed, 'version'));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/accounts?includeClosed=true');
        self::assertSame(['Compte courant'], array_column($this->items(), 'label'));
        $this->client->request('GET', '/api/v1/accounts?includeClosed=true&includeArchived=true');
        self::assertCount(2, $this->items());

        $this->client->request('GET', '/api/v1/accounts?kind=CURRENT');
        self::assertSame([self::stringValue($open, 'id')], array_column($this->items(), 'id'));
    }

    public function testAnArchivedAccountIsReportedAsReadOnlyRatherThanConflicting(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');
        $this->requestArchive($id, self::intValue($account, 'version'));
        self::assertResponseIsSuccessful();
        $archived = $this->decode();

        // The archive frees the label, so an active namesake exists while the archived row is edited.
        $this->createAccount(label: 'Livret A Banque X');

        $this->requestUpdate($id, $archived);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/account-archived', $this->decode()['type'] ?? null);

        $this->requestArchive($id, self::intValue($archived, 'version'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/account-archived', $this->decode()['type'] ?? null);
    }

    public function testAnActiveLabelIsUniqueIgnoringCaseWithoutEchoingIt(): void
    {
        $this->createAccount(label: 'Livret A Banque X');

        $this->requestCreate($this->payload('livret a banque x'));

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('/problems/account-label-taken', $this->decode()['type'] ?? null);
        self::assertStringNotContainsString('livret a banque x', mb_strtolower((string) $this->client->getResponse()->getContent()));
    }

    public function testInvalidLifecycleValuationAndIdentifierAreRejected(): void
    {
        $this->requestCreate($this->payload('Compte', ['openedOn' => '2999-01-01']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['openedOn' => '2026-02-01', 'closedOn' => '2026-01-01']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['openedOn' => '10/01/2026']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['valuationMode' => 'PORTFOLIO', 'kind' => 'CURRENT']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['maskedIdentifier' => 'FR7630006000011234567890189']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['includeInNetWorth' => false, 'includeInEmergencyFund' => true]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['assetCode' => 'ZZZ']));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->ownAccountCount());
    }

    public function testMassAssignmentAndUnknownFieldsAreRefused(): void
    {
        $body = $this->payload('Compte');
        $body['workspaceId'] = WorkspaceFixture::OTHER_WORKSPACE;
        $this->requestCreate($body);
        self::assertResponseStatusCodeSame(422);

        $body = $this->payload('Compte');
        $body['version'] = 5;
        $this->requestCreate($body);
        self::assertResponseStatusCodeSame(422);

        $account = $this->createAccount(label: 'Livret A Banque X');
        $update = $this->updateBody($account);
        $update['assetCode'] = 'USD';
        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.self::stringValue($account, 'id'),
            server: self::jsonHeaders(),
            content: json_encode($update, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('EUR', $this->connection->fetchOne(
            'SELECT asset_code FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, self::stringValue($account, 'id')],
        ));
    }

    public function testAForeignAccountAnswersExactlyLikeAnUnknownOne(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000df';
        $this->insertAccount($foreignId, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');

        $body = $this->payload('Changed');
        unset($body['assetCode']);
        $body['version'] = 1;
        $body['primaryGroupId'] = null;
        $body['tagGroupIds'] = [];
        $this->client->request('PUT', '/api/v1/accounts/'.$foreignId, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->client->request('PUT', '/api/v1/accounts/00000000-0000-7000-8000-0000000000de', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());

        $this->requestArchive($foreignId, 1);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/accounts?includeArchived=true&includeClosed=true');
        self::assertSame([], $this->items());
    }

    public function testAnUpdateUsesOptimisticVersioning(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [...$account, 'label' => 'Livret A Banque Y']);
        self::assertResponseIsSuccessful();

        // A stale version and a taken label are both 409 and ask for opposite
        // recoveries, so the type is what a client branches on.
        $this->requestUpdate($id, [...$account, 'label' => 'Onglet périmé']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type'] ?? null);

        $this->requestArchive($id, self::intValue($account, 'version'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type'] ?? null);
    }

    public function testAUsedAccountCannotChangeKind(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');
        $this->connection->update(
            'account_financial_accounts',
            ['used_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );

        $this->requestUpdate($id, [...$account, 'kind' => 'CURRENT']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/v1/accounts');
        $used = $this->items()[0];
        self::assertTrue($used['used']);
        self::assertFalse($used['kindEditable']);
        self::assertSame('USED', $used['kindEditReason']);
    }

    public function testAnAccountIsCreatedFromACatalogueProductWithoutCopyingItsRules(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X', overrides: [
            'productCode' => 'FR_LIVRET_A',
            'institution' => 'Banque X',
        ]);

        self::assertSame('FR_LIVRET_A', $account['productCode']);
        self::assertSame('Banque X', $account['institution']);
        self::assertSame('SAVINGS', $account['kind']);

        // The account keeps the reference and nothing else: the 22 950 € ceiling
        // and the 1.7 % rate stay in the catalogue, read on the date they are
        // needed, so a regulatory revision is never frozen into this row.
        $stored = $this->connection->fetchAssociative(
            'SELECT * FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, self::stringValue($account, 'id')],
        );
        self::assertIsArray($stored);
        self::assertSame('FR_LIVRET_A', $stored['product_code']);
        self::assertSame([], array_values(array_filter(
            array_keys($stored),
            static fn (mixed $column): bool => 1 === preg_match('/ceiling|rate|percentage|amount/', (string) $column),
        )));
    }

    /**
     * A PEA is checked against cumulative contributions rather than market
     * value, so it must be filed under the kind its product declares. Accepting
     * a submitted kind would send a later ceiling check to the wrong basis.
     */
    public function testAProductImposesItsKindAndItsCapabilities(): void
    {
        $this->requestCreate($this->payload('PEA Banque X', [
            'productCode' => 'FR_PEA',
            'kind' => 'SAVINGS',
        ]));
        self::assertResponseStatusCodeSame(422);

        // A savings product cannot be valued by positions. The endpoint refuses
        // it; which of the two rules answers first is settled by
        // AccountProductTest, which reaches the capability check in isolation.
        $this->requestCreate($this->payload('Livret A Banque X', [
            'productCode' => 'FR_LIVRET_A',
            'valuationMode' => 'PORTFOLIO',
        ]));
        self::assertResponseStatusCodeSame(422);

        $pea = $this->createAccount(label: 'PEA Banque X', overrides: [
            'productCode' => 'FR_PEA',
            'kind' => 'PORTFOLIO',
            'valuationMode' => 'PORTFOLIO',
            'liquidityLevel' => 'MEDIUM_TERM',
        ]);
        self::assertSame('PORTFOLIO', $pea['kind']);
    }

    public function testAnUnknownProductReferenceIsRefusedByTheUseCaseAndByTheDatabase(): void
    {
        $this->requestCreate($this->payload('Compte', ['productCode' => 'FR_UNKNOWN_PRODUCT']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Compte', ['productCode' => 'not a code']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownAccountCount());

        // The catalogue carries no workspace, so a code is known to every
        // workspace or to none. The foreign key repeats that refusal for any
        // writer that does not come through the use case.
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/product_fk/');
        $this->insertAccount('00000000-0000-7000-8000-0000000000da', WorkspaceFixture::OWN_WORKSPACE, 'Direct', 'FR_UNKNOWN_PRODUCT');
    }

    public function testAnUpdateRevalidatesTheProductKindAndValuationTriple(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X', overrides: [
            'productCode' => 'FR_LIVRET_A',
            'institution' => 'Banque X',
        ]);
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [...$account, 'kind' => 'CURRENT']);
        self::assertResponseStatusCodeSame(422);

        $this->requestUpdate($id, [...$account, 'productCode' => 'FR_UNKNOWN_PRODUCT']);
        self::assertResponseStatusCodeSame(422);

        // Everything outside the triple stays editable on a product-backed account.
        $this->requestUpdate($id, [...$account, 'institution' => 'Banque Y']);
        self::assertResponseIsSuccessful();
        self::assertSame('Banque Y', $this->decode()['institution']);
    }

    public function testAUsedAccountCannotChangeProduct(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X', overrides: ['productCode' => 'FR_LIVRET_A']);
        $id = self::stringValue($account, 'id');
        $this->connection->update(
            'account_financial_accounts',
            ['used_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );

        $this->requestUpdate($id, [...$account, 'productCode' => 'FR_LDDS']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnAccountIsCreatedFromAWorkspaceModelWithoutCopyingItsRules(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));

        $account = $this->createAccount(label: 'Livret Banque X', overrides: [
            'productModelId' => ProductModelFixture::ID,
        ]);

        self::assertSame(ProductModelFixture::ID, $account['productModelId']);
        self::assertNull($account['productCode']);
        self::assertSame('SAVINGS', $account['kind']);

        // The account keeps the reference and nothing else: any dated period
        // the model carries stays on the model, read on the date it is
        // needed, never frozen into this row.
        $stored = $this->connection->fetchAssociative(
            'SELECT * FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, self::stringValue($account, 'id')],
        );
        self::assertIsArray($stored);
        self::assertSame(ProductModelFixture::ID, $stored['product_model_id']);
        self::assertNull($stored['product_code']);

        $created = $this->connection->fetchAssociative(
            "SELECT after_json FROM audit_events
             WHERE workspace_id = ? AND event_type = 'account.created' AND entity_id = ?",
            [WorkspaceFixture::OWN_WORKSPACE, self::stringValue($account, 'id')],
        );
        self::assertIsArray($created);
        self::assertIsString($created['after_json']);
        $fingerprint = json_decode($created['after_json'], true);
        self::assertIsArray($fingerprint);
        self::assertSame(ProductModelFixture::ID, $fingerprint['productModelId']);
        self::assertArrayNotHasKey('label', $fingerprint);
    }

    public function testTwoAccountsCanShareOneWorkspaceModel(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));

        $first = $this->createAccount(label: 'Livret Banque X', overrides: [
            'productModelId' => ProductModelFixture::ID,
        ]);
        $second = $this->createAccount(label: 'Livret Banque Y', overrides: [
            'productModelId' => ProductModelFixture::ID,
        ]);

        self::assertSame(ProductModelFixture::ID, $first['productModelId']);
        self::assertSame(ProductModelFixture::ID, $second['productModelId']);
        self::assertNotSame($first['id'], $second['id']);
    }

    /**
     * A model checked against a kind or a valuation mode it does not declare
     * would later read a ceiling or a rate against the wrong basis, exactly
     * as a catalogue product would.
     */
    public function testAModelImposesItsKindAndItsCapabilities(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));

        $this->requestCreate($this->payload('Livret Banque X', [
            'productModelId' => ProductModelFixture::ID,
            'kind' => 'CURRENT',
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload('Livret Banque X', [
            'productModelId' => ProductModelFixture::ID,
            'valuationMode' => 'PORTFOLIO',
        ]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnknownOrForeignModelReferenceIsRefusedByTheUseCaseAndByTheDatabase(): void
    {
        $this->models->add(ProductModelFixture::model(id: self::OTHER_MODEL, workspace: WorkspaceFixture::OTHER_WORKSPACE));

        $this->requestCreate($this->payload('Compte', ['productModelId' => '00000000-0000-7000-8000-0000000000ff']));
        self::assertResponseStatusCodeSame(422);

        // A model of another workspace answers exactly like an unknown one: the
        // repository scopes the lookup, so neither refusal confirms the id is real.
        $this->requestCreate($this->payload('Compte', ['productModelId' => self::OTHER_MODEL]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownAccountCount());

        // The reference is (id, workspace_id), so the foreign key repeats the
        // refusal for any writer that does not come through the use case.
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/product_model_fk/');
        $this->insertAccount('00000000-0000-7000-8000-0000000000da', WorkspaceFixture::OWN_WORKSPACE, 'Direct', productModelId: self::OTHER_MODEL);
    }

    public function testAnArchivedModelCannotBackANewAccountButKeepsBackingAnExistingOne(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));
        $account = $this->createAccount(label: 'Livret Banque X', overrides: ['productModelId' => ProductModelFixture::ID]);

        $archived = ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE)
            ->archive(new \DateTimeImmutable('2026-09-04T11:00:00+00:00'));
        self::assertTrue($this->models->update($archived, 1));

        // A new account cannot start on an archived model.
        $this->requestCreate($this->payload('Second livret', ['productModelId' => ProductModelFixture::ID]));
        self::assertResponseStatusCodeSame(422);

        // The account already backed by it is untouched: renaming it does not
        // resolve the model again.
        $this->requestUpdate(self::stringValue($account, 'id'), [...$account, 'label' => 'Livret Banque X (2)']);
        self::assertResponseIsSuccessful();
        self::assertSame(ProductModelFixture::ID, $this->decode()['productModelId']);
    }

    public function testAnAccountCannotReferenceBothAProductAndAModel(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));

        $this->requestCreate($this->payload('Compte', [
            'productCode' => 'FR_LIVRET_A',
            'productModelId' => ProductModelFixture::ID,
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ownAccountCount());
    }

    public function testAnUnusedAccountCanMoveFromACatalogueProductToAWorkspaceModel(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));
        $account = $this->createAccount(label: 'Livret A Banque X', overrides: ['productCode' => 'FR_LIVRET_A']);
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [
            ...$account,
            'productCode' => null,
            'productModelId' => ProductModelFixture::ID,
        ]);
        self::assertResponseIsSuccessful();

        $stored = $this->connection->fetchAssociative(
            'SELECT product_code, product_model_id FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $id],
        );
        self::assertIsArray($stored);
        self::assertNull($stored['product_code']);
        self::assertSame(ProductModelFixture::ID, $stored['product_model_id']);
    }

    public function testAnUnusedAccountCanLeaveItsWorkspaceModel(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));
        $account = $this->createAccount(label: 'Livret Banque X', overrides: [
            'productModelId' => ProductModelFixture::ID,
        ]);
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [...$account, 'productCode' => null, 'productModelId' => null]);
        self::assertResponseIsSuccessful();

        $stored = $this->connection->fetchAssociative(
            'SELECT product_code, product_model_id FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $id],
        );
        self::assertIsArray($stored);
        self::assertNull($stored['product_code']);
        self::assertNull($stored['product_model_id']);
    }

    public function testAUsedAccountCannotChangeModel(): void
    {
        $this->models->add(ProductModelFixture::model(workspace: WorkspaceFixture::OWN_WORKSPACE));
        $this->models->add(ProductModelFixture::model(id: self::OTHER_MODEL, workspace: WorkspaceFixture::OWN_WORKSPACE, name: 'Autre livret'));

        $account = $this->createAccount(label: 'Livret Banque X', overrides: ['productModelId' => ProductModelFixture::ID]);
        $id = self::stringValue($account, 'id');
        $this->connection->update(
            'account_financial_accounts',
            ['used_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );

        $this->requestUpdate($id, [...$account, 'productModelId' => self::OTHER_MODEL]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMutationsRequireCsrfAndAuditWithoutFinancialLabels(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestCreate($this->payload('Secret household account'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->ownAccountCount());

        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $account = $this->createAccount(label: 'Secret household account', overrides: ['maskedIdentifier' => '4821']);
        $id = self::stringValue($account, 'id');

        $this->requestUpdate($id, [...$account, 'closedOn' => '2026-02-01']);
        self::assertResponseIsSuccessful();
        $this->requestArchive($id, self::intValue($this->decode(), 'version'));
        self::assertResponseIsSuccessful();

        $events = $this->connection->fetchAllAssociative(
            "SELECT event_type, entity_type, entity_id, actor_id, after_json FROM audit_events
             WHERE workspace_id = ? AND event_type LIKE 'account.%' ORDER BY occurred_at, event_type",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertSame(
            ['account.created', 'account.updated', 'account.closed', 'account.archived'],
            array_column($events, 'event_type'),
        );
        foreach ($events as $event) {
            self::assertSame('financial_account', $event['entity_type']);
            self::assertSame($id, $event['entity_id']);
            self::assertSame(WorkspaceFixture::OWNER_ID, $event['actor_id']);
            self::assertIsString($event['after_json']);
            $before = $event['before_json'];
            self::assertTrue(null === $before || is_string($before));
            // Both sides are asserted: a one-sided redaction change must fail here.
            $sides = $event['after_json'].(string) $before;
            self::assertStringNotContainsString('Secret household account', $sides);
            self::assertStringNotContainsString('4821', $sides);
            $fingerprint = json_decode((string) $event['after_json'], true);
            self::assertIsArray($fingerprint);
            // Closing records only the closure flag; the structural origin
            // lives on created, updated and archived, which all use the
            // same fingerprint. Pinning the keys here is what stops a
            // writer from adding an origin without noticing the trail.
            if ('account.closed' !== $event['event_type']) {
                self::assertArrayHasKey('productCode', $fingerprint);
                self::assertArrayHasKey('productModelId', $fingerprint);
            }
        }
    }

    public function testAnUnknownOrForeignGroupAssignmentIsRefused(): void
    {
        $account = $this->createAccount(label: 'Livret A Banque X');
        $id = self::stringValue($account, 'id');
        $unknown = '00000000-0000-7000-8000-0000000000ff';

        $this->requestUpdate($id, [...$account, 'primaryGroupId' => $unknown]);
        self::assertResponseStatusCodeSame(422);

        $this->connection->insert('account_groups', [
            'id' => $unknown,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'label' => 'Private group',
            'parent_id' => null,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);

        $this->requestUpdate($id, [...$account, 'primaryGroupId' => $unknown]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('Private group', (string) $this->client->getResponse()->getContent());

        $stored = $this->connection->fetchOne(
            'SELECT primary_group_id FROM account_financial_accounts WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $id],
        );
        self::assertNull($stored);
    }

    public function testAnAccountCanBeCreatedWithAnOptionalPrimaryGroup(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/account-groups',
            server: self::jsonHeaders(),
            content: json_encode(['label' => 'Épargne', 'parentId' => null, 'sortOrder' => 0], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $group = $this->decode();

        $ungrouped = $this->createAccount(label: 'Compte courant');
        self::assertNull($ungrouped['primaryGroupId']);
        self::assertSame([], $ungrouped['tagGroupIds']);

        $grouped = $this->createAccount(label: 'Livret A Banque X', overrides: [
            'primaryGroupId' => $group['id'],
            'tagGroupIds' => [],
        ]);
        self::assertSame($group['id'], $grouped['primaryGroupId']);
        self::assertSame([], $grouped['tagGroupIds']);

        $this->requestCreate($this->payload('Livret refusé', [
            'primaryGroupId' => '00000000-0000-7000-8000-0000000000ff',
        ]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnAccountCanBeAssignedAPrimaryGroupAndTags(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/account-groups',
            server: self::jsonHeaders(),
            content: json_encode(['label' => 'Épargne', 'parentId' => null, 'sortOrder' => 0], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $primary = $this->decode();
        $this->client->request(
            'POST',
            '/api/v1/account-groups',
            server: self::jsonHeaders(),
            content: json_encode(['label' => 'Liquidités', 'parentId' => null, 'sortOrder' => 1], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $tag = $this->decode();

        $account = $this->createAccount(label: 'Livret A Banque X');
        $this->requestUpdate(self::stringValue($account, 'id'), [
            ...$account,
            'primaryGroupId' => $primary['id'],
            'tagGroupIds' => [$tag['id']],
        ]);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame($primary['id'], $updated['primaryGroupId']);
        self::assertSame([$tag['id']], $updated['tagGroupIds']);
        $share = $updated['share'];
        self::assertIsArray($share);
        self::assertSame('MISSING_VALUATION', $share['reason']);
        self::assertNull($share['percent']);
    }

    public function testQueryBoundsAndPayloadSizeAreEnforced(): void
    {
        $this->client->request('GET', '/api/v1/accounts?perPage=0');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?perPage=101');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?page=1001');
        self::assertResponseStatusCodeSame(400);

        // Five digits never reach the use case: the controller bounds the shape first.
        $this->client->request('GET', '/api/v1/accounts?page=10000');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?kind=UNKNOWN');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/accounts?includeArchived=maybe');
        self::assertResponseStatusCodeSame(400);

        $this->requestCreate($this->payload(str_repeat('x', 17_000)));
        self::assertResponseStatusCodeSame(413);

        $this->client->request('POST', '/api/v1/accounts', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
        self::assertResponseStatusCodeSame(415);

        $this->createAccount(label: str_repeat('é', 80));
        $this->requestCreate($this->payload(str_repeat('a', 81)));
        self::assertResponseStatusCodeSame(422);

        // A JSON string is not an integer: the version is asserted, never coerced.
        $account = $this->createAccount(label: 'Compte courant');
        $body = $this->updateBody($account);
        $body['version'] = (string) self::intValue($account, 'version');
        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.self::stringValue($account, 'id'),
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    private function signIn(): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createAccount(string $label, array $overrides = []): array
    {
        $this->requestCreate($this->payload($label, $overrides));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $account */
    private function requestUpdate(string $id, array $account): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/accounts/'.$id,
            server: self::jsonHeaders(),
            content: json_encode($this->updateBody($account), JSON_THROW_ON_ERROR),
        );
    }

    private function requestArchive(string $id, int $version): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts/'.$id.'/archive',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $version], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function updateBody(array $account): array
    {
        return [
            'label' => $account['label'],
            'kind' => $account['kind'],
            'productCode' => $account['productCode'],
            'productModelId' => $account['productModelId'],
            'institution' => $account['institution'],
            'maskedIdentifier' => $account['maskedIdentifier'],
            'valuationMode' => $account['valuationMode'],
            'liquidityLevel' => $account['liquidityLevel'],
            'includeInNetWorth' => $account['includeInNetWorth'],
            'includeInEmergencyFund' => $account['includeInEmergencyFund'],
            'openedOn' => $account['openedOn'],
            'closedOn' => $account['closedOn'],
            'version' => $account['version'],
            'primaryGroupId' => $account['primaryGroupId'] ?? null,
            'tagGroupIds' => $account['tagGroupIds'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(string $label, array $overrides = []): array
    {
        return [
            'label' => $label,
            'assetCode' => 'EUR',
            'kind' => 'SAVINGS',
            'productCode' => null,
            'productModelId' => null,
            'institution' => null,
            'maskedIdentifier' => null,
            'valuationMode' => 'TRANSACTIONS',
            'liquidityLevel' => 'IMMEDIATE',
            'includeInNetWorth' => true,
            'includeInEmergencyFund' => false,
            'openedOn' => '2026-01-10',
            'closedOn' => null,
            'primaryGroupId' => null,
            'tagGroupIds' => [],
            ...$overrides,
        ];
    }

    private function ownAccountCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM account_financial_accounts WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function insertAccount(
        string $id,
        string $workspaceId,
        string $label,
        ?string $productCode = null,
        ?string $productModelId = null,
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => 'SAVINGS',
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

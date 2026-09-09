<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TransactionControllerTest extends WebTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OWN_EXPENSE = '00000000-0000-7000-8000-0000000000c1';
    private const string OWN_INCOME = '00000000-0000-7000-8000-0000000000c2';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string FOREIGN_TRANSACTION = '00000000-0000-7000-8000-0000000000f9';
    private const string UNKNOWN_TRANSACTION = '00000000-0000-7000-8000-0000000000f8';

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
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');
        $this->seedCategory(self::OWN_EXPENSE, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Courses');
        $this->seedCategory(self::OWN_INCOME, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire');
        $this->seedCategory(self::OTHER_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, 'EXPENSE', 'Privé voisin');

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

    public function testTheOwnerCreatesReadsEditsVoidsAndDuplicatesAMovement(): void
    {
        $created = $this->createTransaction();
        self::assertSame([
            'id', 'accountId', 'amount', 'originalAmount', 'exchangeRate', 'nature', 'state', 'source',
            'bookedOn', 'valueOn', 'authorizedOn', 'rawLabel', 'counterparty', 'note', 'paymentMethod',
            'mcc', 'maskedCard', 'bankReference', 'splits', 'version', 'createdAt', 'updatedAt', 'voidedAt',
        ], array_keys($created));
        self::assertSame(['value' => '-42.90', 'assetCode' => 'EUR'], $created['amount']);
        self::assertNull($created['originalAmount']);
        self::assertSame('EXPENSE', $created['nature']);
        self::assertSame('BOOKED', $created['state']);
        self::assertSame('MANUAL', $created['source']);
        self::assertSame('CB CARREFOUR 1234', $created['rawLabel']);
        self::assertSame('Carrefour', $created['counterparty']);
        $splits = $created['splits'];
        self::assertIsList($splits);
        self::assertCount(1, $splits);
        self::assertIsArray($splits[0]);
        self::assertSame(self::OWN_EXPENSE, $splits[0]['categoryId']);
        self::assertSame('Courses', $splits[0]['categoryLabel']);
        self::assertSame(['value' => '-42.90', 'assetCode' => 'EUR'], $splits[0]['amount']);
        self::assertSame(1, $created['version']);

        $id = self::stringValue($created, 'id');
        $this->client->request('GET', '/api/v1/transactions/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame($id, $this->decode()['id']);

        $this->requestUpdate($id, [
            ...$created,
            'rawLabel' => 'CARREFOUR MARKET',
            'counterparty' => 'Carrefour Market',
            'note' => 'Corrigé',
        ]);
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        self::assertSame('Carrefour Market', $edited['counterparty']);
        self::assertSame('Corrigé', $edited['note']);
        self::assertSame('CARREFOUR MARKET', $edited['rawLabel']);
        self::assertSame(self::OWN_ACCOUNT, $edited['accountId']);
        self::assertSame(2, $edited['version']);

        $this->requestVoid($id, 2);
        self::assertResponseIsSuccessful();
        $voided = $this->decode();
        self::assertSame('VOIDED', $voided['state']);
        self::assertNotNull($voided['voidedAt']);

        $this->client->request('GET', '/api/v1/transactions');
        self::assertSame([], $this->items());
        $this->client->request('GET', '/api/v1/transactions?includeVoided=true');
        self::assertSame([$id], array_column($this->items(), 'id'));

        $this->requestDuplicate($id);
        self::assertResponseStatusCodeSame(201);
        $duplicate = $this->decode();
        self::assertNotSame($id, $duplicate['id']);
        self::assertSame('BOOKED', $duplicate['state']);
        self::assertSame('MANUAL', $duplicate['source']);
        self::assertNull($duplicate['bankReference']);
        self::assertNull($duplicate['maskedCard']);
        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'), $duplicate['bookedOn']);
        self::assertSame(['value' => '-42.90', 'assetCode' => 'EUR'], $duplicate['amount']);
        self::assertSame('CARREFOUR MARKET', $duplicate['rawLabel']);
        self::assertSame(self::OWN_ACCOUNT, $duplicate['accountId']);
        self::assertSame('Carrefour Market', $duplicate['counterparty']);
        self::assertSame('Corrigé', $duplicate['note']);
        self::assertSame('CARD', $duplicate['paymentMethod']);
        $duplicateSplits = $duplicate['splits'] ?? null;
        self::assertIsList($duplicateSplits);
        self::assertIsArray($duplicateSplits[0] ?? null);
        self::assertSame('Courses', $duplicateSplits[0]['categoryLabel']);
    }

    public function testSubmittedScaleIsPreservedBesideTheNumericValue(): void
    {
        $created = $this->createTransaction(overrides: [
            'amount' => ['value' => '-1.50', 'assetCode' => 'EUR'],
        ]);
        $amount = $created['amount'];
        self::assertIsArray($amount);
        self::assertSame('-1.50', $amount['value']);
        $scale = $this->connection->fetchOne(
            'SELECT amount_scale FROM transaction_transactions WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $created['id']],
        );
        self::assertTrue(is_int($scale) || is_string($scale));
        self::assertSame(2, (int) $scale);
    }

    public function testInvalidAmountsDatesAndCategoriesAreRejectedWithoutAWrite(): void
    {
        $this->requestCreate($this->payload(overrides: [
            'amount' => ['value' => '-42.90', 'assetCode' => 'USD'],
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: [
            'amount' => ['value' => '0.00', 'assetCode' => 'EUR'],
            'nature' => 'ADJUSTMENT',
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: [
            'amount' => ['value' => '42.90', 'assetCode' => 'EUR'],
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: ['bookedOn' => '2025-12-31']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: ['bookedOn' => '2099-01-01']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: ['categoryId' => self::OTHER_CATEGORY]));
        self::assertResponseStatusCodeSame(404);

        $this->requestCreate($this->payload(overrides: ['categoryId' => self::OWN_INCOME]));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: ['accountId' => self::OTHER_ACCOUNT]));
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->ownTransactionCount());
    }

    public function testZeroAmountWithAnIncomeCategoryIsRejectedForCreationAndEdition(): void
    {
        $this->requestCreate($this->payload(overrides: [
            'amount' => ['value' => '0.00', 'assetCode' => 'EUR'],
            'nature' => 'ADJUSTMENT',
            'categoryId' => self::OWN_INCOME,
        ]));
        self::assertResponseStatusCodeSame(422);

        $created = $this->createTransaction();
        $this->requestUpdate(self::stringValue($created, 'id'), [
            ...$created,
            'amount' => ['value' => '0.00', 'assetCode' => 'EUR'],
            'nature' => 'ADJUSTMENT',
            'categoryId' => self::OWN_INCOME,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->decodeFromRow(self::stringValue($created, 'id'))['version']);
    }

    public function testEditingKeepsAnExistingArchivedCategoryAssignment(): void
    {
        $created = $this->createTransaction();
        $splits = $created['splits'] ?? null;
        self::assertIsList($splits);
        $split = $splits[0] ?? null;
        self::assertIsArray($split);
        $splitId = $split['id'] ?? null;
        self::assertIsString($splitId);
        $createdAt = $this->connection->fetchOne(
            'SELECT created_at FROM transaction_splits WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $splitId],
        );
        self::assertIsString($createdAt);
        $this->connection->update('category_categories', [
            'archived_at' => '2026-03-15 09:00:00+00',
            'version' => 2,
        ], [
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'id' => self::OWN_EXPENSE,
        ]);

        $this->requestUpdate(self::stringValue($created, 'id'), [...$created, 'note' => 'Corrigé']);
        self::assertResponseIsSuccessful();
        $edited = $this->decode();
        self::assertSame('Corrigé', $edited['note']);
        $editedSplits = $edited['splits'] ?? null;
        self::assertIsList($editedSplits);
        self::assertIsArray($editedSplits[0] ?? null);
        self::assertSame($splitId, $editedSplits[0]['id']);
        self::assertSame($createdAt, $this->connection->fetchOne(
            'SELECT created_at FROM transaction_splits WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $splitId],
        ));

        $this->requestCreate($this->payload(overrides: ['rawLabel' => 'Nouvelle affectation']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testStandaloneCreationRejectsTransferAndRefundNatures(): void
    {
        foreach (['TRANSFER', 'REFUND'] as $nature) {
            $this->requestCreate($this->payload(overrides: ['nature' => $nature, 'categoryId' => null]));
            self::assertResponseStatusCodeSame(422);
        }

        self::assertSame(0, $this->ownTransactionCount());
    }

    public function testStandaloneUpdateRejectsTransferAndRefundNatures(): void
    {
        $created = $this->createTransaction();

        foreach (['TRANSFER', 'REFUND'] as $nature) {
            $this->requestUpdate(self::stringValue($created, 'id'), [
                ...$created,
                'nature' => $nature,
                'categoryId' => null,
            ]);
            self::assertResponseStatusCodeSame(422);
        }

        $stored = $this->decodeFromRow(self::stringValue($created, 'id'));
        self::assertSame('EXPENSE', $stored['nature']);
        self::assertSame(1, $stored['version']);
    }

    public function testMalformedReferenceIdentifiersAreValidationErrors(): void
    {
        $this->requestCreate($this->payload(overrides: ['accountId' => 'not-a-uuid']));
        self::assertResponseStatusCodeSame(422);

        $this->requestCreate($this->payload(overrides: ['categoryId' => 'not-a-uuid']));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->ownTransactionCount());
    }

    public function testAnUpdateUsesOptimisticVersioningAndKeepsTheAccountImmutable(): void
    {
        $created = $this->createTransaction();
        $id = self::stringValue($created, 'id');
        $this->requestUpdate($id, [...$created, 'note' => 'Premier onglet']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);

        $this->requestUpdate($id, [...$created, 'note' => 'Ancien onglet']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type']);

        $this->requestUpdate($id, [...$this->decodeFromRow($id), 'accountId' => self::OTHER_ACCOUNT]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUpdateKeepsAnImportedRawLabelImmutable(): void
    {
        $created = $this->createTransaction();
        $id = self::stringValue($created, 'id');
        $this->connection->update('transaction_transactions', ['source' => 'IMPORT'], ['id' => $id]);

        $this->requestUpdate($id, [...$this->decodeFromRow($id), 'rawLabel' => 'Autre libellé']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('CB CARREFOUR 1234', $this->decodeFromRow($id)['rawLabel']);
    }

    public function testVoidingATerminalTransactionConflictsAndLeavesTheRow(): void
    {
        $created = $this->createTransaction();
        $id = self::stringValue($created, 'id');
        $this->requestVoid($id, 1);
        self::assertResponseIsSuccessful();

        $this->requestVoid($id, 2);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/transaction-conflict', $this->decode()['type']);

        $this->requestUpdate($id, [...$this->decodeFromRow($id), 'note' => 'Après annulation']);
        self::assertResponseStatusCodeSame(409);

        self::assertSame(1, $this->ownTransactionCount());
    }

    public function testAForeignTransactionAnswersExactlyLikeAnUnknownOne(): void
    {
        $this->seedForeignTransaction();

        $this->client->request('GET', '/api/v1/transactions/'.self::FOREIGN_TRANSACTION);
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Secret neighbour label', $foreign);

        $this->client->request('GET', '/api/v1/transactions/'.self::UNKNOWN_TRANSACTION);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
    }

    public function testForeignMutationsAnswerExactlyLikeUnknownTransactions(): void
    {
        $this->seedForeignTransaction();

        foreach (['update', 'void', 'duplicate'] as $operation) {
            $this->requestMutation($operation, self::FOREIGN_TRANSACTION);
            self::assertResponseStatusCodeSame(404);
            $foreign = (string) $this->client->getResponse()->getContent();

            $this->requestMutation($operation, self::UNKNOWN_TRANSACTION);
            self::assertResponseStatusCodeSame(404);
            self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
        }

        self::assertSame(0, $this->ownTransactionCount());
        $otherCount = $this->connection->fetchOne(
            'SELECT count(*) FROM transaction_transactions WHERE workspace_id = ?',
            [WorkspaceFixture::OTHER_WORKSPACE],
        );
        self::assertTrue(is_int($otherCount) || is_string($otherCount));
        self::assertSame(1, (int) $otherCount);
    }

    public function testListingPagesNewestFirstAndRejectsAnUndecodableCursor(): void
    {
        $first = $this->createTransaction(overrides: ['bookedOn' => '2026-03-10', 'rawLabel' => 'Ancien']);
        $second = $this->createTransaction(overrides: ['bookedOn' => '2026-03-14', 'rawLabel' => 'Récent']);

        $this->client->request('GET', '/api/v1/transactions?pageSize=1');
        self::assertResponseIsSuccessful();
        $page = $this->decode();
        self::assertSame([$second['id']], array_column($this->items(), 'id'));
        self::assertIsString($page['nextCursor']);

        $this->client->request('GET', '/api/v1/transactions?pageSize=1&cursor='.rawurlencode((string) $page['nextCursor']));
        self::assertSame([$first['id']], array_column($this->items(), 'id'));
        self::assertNull($this->decode()['nextCursor']);

        $this->client->request('GET', '/api/v1/transactions?cursor=%%%');
        self::assertResponseStatusCodeSame(400);
    }

    public function testMutationsRequireCsrfAndWriteARedactedAuditEvent(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestCreate($this->payload());
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $created = $this->createTransaction();

        $event = $this->connection->fetchAssociative(
            "SELECT actor_id, event_type, entity_id, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'transaction.created'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsArray($event);
        self::assertSame(WorkspaceFixture::OWNER_ID, $event['actor_id']);
        self::assertSame($created['id'], $event['entity_id']);
        self::assertIsString($event['after_json']);
        self::assertStringNotContainsString('CB CARREFOUR 1234', $event['after_json']);
        self::assertStringNotContainsString('Carrefour', $event['after_json']);
        self::assertStringNotContainsString('-42.90', $event['after_json']);
        self::assertStringNotContainsString('42.90', $event['after_json']);

        $this->requestUpdate(self::stringValue($created, 'id'), [...$created, 'note' => 'Sensitive update']);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        $updatedVersion = $updated['version'] ?? null;
        self::assertIsInt($updatedVersion);
        $this->requestVoid(self::stringValue($updated, 'id'), $updatedVersion);
        self::assertResponseIsSuccessful();
        $this->requestDuplicate(self::stringValue($updated, 'id'));
        self::assertResponseStatusCodeSame(201);

        $events = $this->connection->fetchAllAssociative(
            "SELECT event_type, before_json, after_json FROM audit_events WHERE workspace_id = ? AND event_type LIKE 'transaction.%' ORDER BY occurred_at, id",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertSame(
            ['transaction.created', 'transaction.updated', 'transaction.voided', 'transaction.duplicated'],
            array_column($events, 'event_type'),
        );
        $encodedEvents = json_encode($events, JSON_THROW_ON_ERROR);
        foreach (['CB CARREFOUR 1234', 'Carrefour', 'Sensitive update', '-42.90', '42.90'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $encodedEvents);
        }
    }

    public function testMassAssignmentAndOversizedBodiesAreRejected(): void
    {
        $body = $this->payload();
        $body['workspaceId'] = WorkspaceFixture::OTHER_WORKSPACE;
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $oversized = $this->payload(overrides: ['note' => str_repeat('x', 33_000)]);
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode($oversized, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(413);

        $this->client->request('POST', '/api/v1/transactions', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
        self::assertResponseStatusCodeSame(415);
        self::assertSame(0, $this->ownTransactionCount());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createTransaction(array $overrides = []): array
    {
        $this->requestCreate($this->payload(overrides: $overrides));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $transaction */
    private function requestUpdate(string $id, array $transaction): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/transactions/'.$id,
            server: self::jsonHeaders(),
            content: json_encode($this->updateBody($transaction), JSON_THROW_ON_ERROR),
        );
    }

    private function requestVoid(string $id, int $version): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transactions/'.$id.'/void',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $version], JSON_THROW_ON_ERROR),
        );
    }

    private function requestDuplicate(string $id): void
    {
        $this->client->request('POST', '/api/v1/transactions/'.$id.'/duplicate', server: self::jsonHeaders(), content: '{}');
    }

    private function requestMutation(string $operation, string $id): void
    {
        match ($operation) {
            'update' => $this->requestUpdate($id, $this->payload(overrides: ['version' => 1])),
            'void' => $this->requestVoid($id, 1),
            'duplicate' => $this->requestDuplicate($id),
            default => throw new \UnexpectedValueException('Unknown transaction mutation.'),
        };
    }

    private function seedForeignTransaction(): void
    {
        $accountId = '00000000-0000-7000-8000-0000000000d9';
        $this->seedAccount($accountId, WorkspaceFixture::OTHER_WORKSPACE, 'Étranger');
        $this->connection->insert('transaction_transactions', [
            'id' => self::FOREIGN_TRANSACTION,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'account_id' => $accountId,
            'asset_code' => 'EUR',
            'amount_value' => '-10.00',
            'amount_scale' => 2,
            'state' => 'BOOKED',
            'nature' => 'EXPENSE',
            'source' => 'MANUAL',
            'booked_on' => '2026-03-14',
            'raw_label' => 'Secret neighbour label',
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'accountId' => self::OWN_ACCOUNT,
            'amount' => ['value' => '-42.90', 'assetCode' => 'EUR'],
            'nature' => 'EXPENSE',
            'state' => 'BOOKED',
            'bookedOn' => '2026-03-14',
            'valueOn' => null,
            'authorizedOn' => null,
            'rawLabel' => 'CB CARREFOUR 1234',
            'counterparty' => 'Carrefour',
            'note' => null,
            'paymentMethod' => 'CARD',
            'mcc' => null,
            'maskedCard' => null,
            'bankReference' => null,
            'categoryId' => self::OWN_EXPENSE,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     *
     * @return array<string, mixed>
     */
    private function updateBody(array $transaction): array
    {
        $splits = $transaction['splits'] ?? null;
        $first = is_array($splits) ? ($splits[0] ?? null) : null;

        return [
            'accountId' => $transaction['accountId'],
            'amount' => $transaction['amount'],
            'nature' => $transaction['nature'],
            'state' => 'VOIDED' === $transaction['state'] ? 'BOOKED' : $transaction['state'],
            'bookedOn' => $transaction['bookedOn'],
            'valueOn' => $transaction['valueOn'],
            'authorizedOn' => $transaction['authorizedOn'],
            'rawLabel' => $transaction['rawLabel'],
            'counterparty' => $transaction['counterparty'],
            'note' => $transaction['note'] ?? null,
            'paymentMethod' => $transaction['paymentMethod'],
            'mcc' => $transaction['mcc'],
            'maskedCard' => $transaction['maskedCard'],
            'bankReference' => $transaction['bankReference'],
            'categoryId' => is_array($first) ? ($first['categoryId'] ?? null) : null,
            'version' => $transaction['version'],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeFromRow(string $id): array
    {
        $this->client->request('GET', '/api/v1/transactions/'.$id);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function seedAccount(string $id, string $workspace, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id,
            'workspace_id' => $workspace,
            'label' => $label,
            'asset_code' => 'EUR',
            'kind' => 'CURRENT',
            'masked_identifier' => null,
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => false,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-01',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedCategory(string $id, string $workspace, string $type, string $label): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id,
            'workspace_id' => $workspace,
            'type' => $type,
            'label' => $label,
            'parent_id' => null,
            'icon' => null,
            'color' => null,
            'default_analytic_axes' => '[]',
            'budget_included' => true,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    private function ownTransactionCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM transaction_transactions WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
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

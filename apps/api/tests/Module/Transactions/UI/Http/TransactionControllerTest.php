<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
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
    private const string OWN_PLAIN_EXPENSE = '00000000-0000-7000-8000-0000000000c4';
    private const string OWN_EXPENSE_WITH_DEFAULT_AXES = '00000000-0000-7000-8000-0000000000c5';
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
        $this->seedCategory(self::OWN_EXPENSE, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Courses', 'basket', '#2E7D32');
        $this->seedCategory(self::OWN_INCOME, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire');
        $this->seedCategory(self::OWN_PLAIN_EXPENSE, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Divers');
        $this->seedCategory(
            self::OWN_EXPENSE_WITH_DEFAULT_AXES, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Loyer',
            defaultAnalyticAxes: ['ESSENTIAL'],
        );
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
        // The split carries the display identity of its category so a transaction
        // row draws the same marker as the categories screen.
        self::assertSame('basket', $splits[0]['categoryIcon']);
        self::assertSame('#2E7D32', $splits[0]['categoryColor']);
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

    public function testASplitExposesNoDisplayMetadataWhenItsCategoryHasNone(): void
    {
        $created = $this->createTransaction(overrides: ['categoryId' => self::OWN_PLAIN_EXPENSE]);

        $splits = $created['splits'];
        self::assertIsList($splits);
        self::assertIsArray($splits[0]);
        self::assertSame('Divers', $splits[0]['categoryLabel']);
        self::assertNull($splits[0]['categoryIcon']);
        self::assertNull($splits[0]['categoryColor']);
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

        foreach (['update', 'void', 'duplicate', 'replaceSplits'] as $operation) {
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

    public function testASplitAllocationSummingExactlyToTheAmountIsAcceptedAndClearingItReturnsTheQueue(): void
    {
        $created = $this->createTransaction(overrides: ['amount' => ['value' => '-87.40', 'assetCode' => 'EUR']]);
        $id = self::stringValue($created, 'id');

        $this->requestReplaceSplits($id, 1, [
            $this->splitRow(self::OWN_EXPENSE, '-62.10', ['ESSENTIAL']),
            $this->splitRow(self::OWN_PLAIN_EXPENSE, '-25.30'),
        ]);
        self::assertResponseIsSuccessful();
        $replaced = $this->decode();
        $replacedSplits = $replaced['splits'];
        self::assertIsList($replacedSplits);
        self::assertCount(2, $replacedSplits);
        self::assertIsArray($replacedSplits[0]);
        self::assertSame(['ESSENTIAL'], $replacedSplits[0]['analyticAxes']);
        self::assertSame(2, $replaced['version']);

        $this->client->request('GET', '/api/v1/transactions?categorization=NONE');
        self::assertSame([], $this->items());

        $this->requestReplaceSplits($id, 2, []);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode()['splits']);

        $this->client->request('GET', '/api/v1/transactions?categorization=NONE');
        self::assertSame([$id], array_column($this->items(), 'id'));
    }

    public function testASplitAllocationOffByOneCentIsRefusedWithTheMissingAmount(): void
    {
        $created = $this->createTransaction(overrides: ['amount' => ['value' => '-87.40', 'assetCode' => 'EUR']]);

        $this->requestReplaceSplits(self::stringValue($created, 'id'), 1, [
            $this->splitRow(self::OWN_EXPENSE, '-62.10'),
            $this->splitRow(self::OWN_PLAIN_EXPENSE, '-25.29'),
        ]);
        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('/problems/splits.sum_mismatch', $problem['type']);
    }

    public function testMoreThanTwentySplitsAreRefused(): void
    {
        $created = $this->createTransaction(overrides: ['amount' => ['value' => '-21.00', 'assetCode' => 'EUR']]);
        $rows = array_map(fn (): array => $this->splitRow(self::OWN_EXPENSE, '-1.00'), range(1, 21));

        $this->requestReplaceSplits(self::stringValue($created, 'id'), 1, $rows);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/splits.too_many', $this->decode()['type']);
    }

    public function testTheSameCategoryTwiceIsRefused(): void
    {
        $created = $this->createTransaction(overrides: ['amount' => ['value' => '-10.00', 'assetCode' => 'EUR']]);

        $this->requestReplaceSplits(self::stringValue($created, 'id'), 1, [
            $this->splitRow(self::OWN_EXPENSE, '-6.00'),
            $this->splitRow(self::OWN_EXPENSE, '-4.00'),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/splits.duplicate_category', $this->decode()['type']);
    }

    public function testASplitOfAnotherWorkspaceCategoryIsRefused(): void
    {
        $created = $this->createTransaction();

        $this->requestReplaceSplits(self::stringValue($created, 'id'), 1, [
            $this->splitRow(self::OTHER_CATEGORY, '-42.90'),
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testReplacingSplitsOnAStaleOrVoidedTransactionIsRejected(): void
    {
        $created = $this->createTransaction();
        $id = self::stringValue($created, 'id');

        $this->requestReplaceSplits($id, 2, [$this->splitRow(self::OWN_EXPENSE, '-42.90')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(TransactionHttpEnvelope::TYPE_STALE_VERSION, $this->decode()['type']);

        $this->requestVoid($id, 1);
        self::assertResponseIsSuccessful();
        $this->requestReplaceSplits($id, 2, [$this->splitRow(self::OWN_EXPENSE, '-42.90')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(TransactionHttpEnvelope::TYPE_CONFLICT, $this->decode()['type']);
    }

    public function testANullAnalyticAxesInheritsTheCategoryDefaultsAndAnEmptyListOverridesThem(): void
    {
        $created = $this->createTransaction();
        $id = self::stringValue($created, 'id');

        $this->requestReplaceSplits($id, 1, [
            $this->splitRow(self::OWN_EXPENSE_WITH_DEFAULT_AXES, '-42.90', null),
        ]);
        self::assertResponseIsSuccessful();
        $inherited = $this->decode()['splits'];
        self::assertIsList($inherited);
        self::assertIsArray($inherited[0]);
        self::assertSame(['ESSENTIAL'], $inherited[0]['analyticAxes']);

        $this->requestReplaceSplits($id, 2, [
            $this->splitRow(self::OWN_EXPENSE_WITH_DEFAULT_AXES, '-42.90', []),
        ]);
        self::assertResponseIsSuccessful();
        $overridden = $this->decode()['splits'];
        self::assertIsList($overridden);
        self::assertIsArray($overridden[0]);
        self::assertSame([], $overridden[0]['analyticAxes']);
    }

    public function testCreatingAndUpdatingThroughTheExplicitSplitsArrayReplacesTheCategoryIdShorthand(): void
    {
        $created = $this->createTransaction(overrides: [
            'amount' => ['value' => '-87.40', 'assetCode' => 'EUR'],
            'categoryId' => null,
            'splits' => [
                $this->splitRow(self::OWN_EXPENSE, '-62.10'),
                $this->splitRow(self::OWN_PLAIN_EXPENSE, '-25.30'),
            ],
        ]);
        $splits = $created['splits'];
        self::assertIsList($splits);
        self::assertCount(2, $splits);

        $id = self::stringValue($created, 'id');
        $body = $this->payload(overrides: [
            'amount' => ['value' => '-87.40', 'assetCode' => 'EUR'],
            'categoryId' => null,
        ]);

        // Changing the amount without a matching allocation is refused...
        $this->requestRawUpdate($id, [
            ...$body,
            'amount' => ['value' => '-90.00', 'assetCode' => 'EUR'],
            'splits' => [
                $this->splitRow(self::OWN_EXPENSE, '-62.10'),
                $this->splitRow(self::OWN_PLAIN_EXPENSE, '-25.30'),
            ],
            'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/splits.sum_mismatch', $this->decode()['type']);

        // ...and using the categoryId shorthand on a multi-split transaction is refused too.
        $this->requestRawUpdate($id, [
            ...$body,
            'splits' => null,
            'categoryId' => self::OWN_EXPENSE,
            'version' => 1,
        ]);
        self::assertResponseStatusCodeSame(422);

        // A matching explicit allocation succeeds.
        $this->requestRawUpdate($id, [
            ...$body,
            'amount' => ['value' => '-90.00', 'assetCode' => 'EUR'],
            'splits' => [
                $this->splitRow(self::OWN_EXPENSE, '-65.00'),
                $this->splitRow(self::OWN_PLAIN_EXPENSE, '-25.00'),
            ],
            'version' => 1,
        ]);
        self::assertResponseIsSuccessful();
        $updatedSplits = $this->decode()['splits'];
        self::assertIsList($updatedSplits);
        self::assertCount(2, $updatedSplits);
    }

    public function testDuplicatingCopiesTheFullSplitAllocation(): void
    {
        $created = $this->createTransaction(overrides: [
            'amount' => ['value' => '-87.40', 'assetCode' => 'EUR'],
            'categoryId' => null,
            'splits' => [
                $this->splitRow(self::OWN_EXPENSE, '-62.10'),
                $this->splitRow(self::OWN_PLAIN_EXPENSE, '-25.30'),
            ],
        ]);

        $this->requestDuplicate(self::stringValue($created, 'id'));
        self::assertResponseStatusCodeSame(201);
        $duplicateSplits = $this->decode()['splits'];
        self::assertIsList($duplicateSplits);
        self::assertCount(2, $duplicateSplits);
    }

    public function testDuplicatingATransactionWhoseCategoryHasSinceBeenArchivedStillSucceeds(): void
    {
        $created = $this->createTransaction(overrides: ['categoryId' => self::OWN_PLAIN_EXPENSE]);
        $this->connection->update(
            'category_categories',
            ['archived_at' => '2026-03-14 09:12:04+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => self::OWN_PLAIN_EXPENSE],
        );

        $this->requestDuplicate(self::stringValue($created, 'id'));

        self::assertResponseStatusCodeSame(201);
        $duplicateSplits = $this->decode()['splits'];
        self::assertIsList($duplicateSplits);
        self::assertCount(1, $duplicateSplits);
    }

    public function testASplitValidationFailureInsideTheDomainIsReportedAsAProblemNotACrash(): void
    {
        $created = $this->createTransaction();

        $this->requestReplaceSplits(self::stringValue($created, 'id'), 1, [
            $this->splitRow(self::OWN_EXPENSE, '-42.90', ['ESSENTIAL', 'ESSENTIAL']),
        ]);

        self::assertResponseStatusCodeSame(422);
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
        $this->requestRawUpdate($id, $this->updateBody($transaction));
    }

    /** @param array<string, mixed> $body already in the wire shape — not derived from a Transaction resource */
    private function requestRawUpdate(string $id, array $body): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/transactions/'.$id,
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
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

    /** @param list<array<string, mixed>> $splits */
    private function requestReplaceSplits(string $id, int $version, array $splits): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/transactions/'.$id.'/splits',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $version, 'splits' => $splits], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<string> $analyticAxes
     *
     * @return array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: list<string>, note: ?string}
     */
    /**
     * @param ?list<string> $analyticAxes
     *
     * @return array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}
     */
    private function splitRow(string $categoryId, string $amount, ?array $analyticAxes = [], ?string $note = null): array
    {
        return [
            'categoryId' => $categoryId,
            'amount' => ['value' => $amount, 'assetCode' => 'EUR'],
            'analyticAxes' => $analyticAxes,
            'note' => $note,
        ];
    }

    private function requestMutation(string $operation, string $id): void
    {
        match ($operation) {
            'update' => $this->requestUpdate($id, $this->payload(overrides: ['version' => 1])),
            'void' => $this->requestVoid($id, 1),
            'duplicate' => $this->requestDuplicate($id),
            'replaceSplits' => $this->requestReplaceSplits($id, 1, []),
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
            'splits' => null,
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
            'splits' => null,
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

    /** @param list<string> $defaultAnalyticAxes */
    private function seedCategory(
        string $id,
        string $workspace,
        string $type,
        string $label,
        ?string $icon = null,
        ?string $color = null,
        array $defaultAnalyticAxes = [],
    ): void {
        $this->connection->insert('category_categories', [
            'id' => $id,
            'workspace_id' => $workspace,
            'type' => $type,
            'label' => $label,
            'parent_id' => null,
            'icon' => $icon,
            'color' => $color,
            'default_analytic_axes' => json_encode($defaultAnalyticAxes, JSON_THROW_ON_ERROR),
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

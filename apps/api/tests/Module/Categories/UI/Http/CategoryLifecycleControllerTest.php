<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\UI\Http;

use App\Module\Categories\Domain\Category;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Moving, merging, archiving and dated replacement, end to end against
 * PostgreSQL: the operations that change how history reads and therefore must
 * preview their impact, refuse a stale confirmation and leave an audit trail.
 */
final class CategoryLifecycleControllerTest extends WebTestCase
{
    use ClosesPeriods;

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

    public function testAMovePreviewsItsBranchThenCarriesEveryDescendantWithIt(): void
    {
        $food = $this->createCategory('Alimentation');
        $restaurants = $this->createCategory('Restaurants', $food['id']);
        $delivery = $this->createCategory('Livraison', $restaurants['id']);
        $leisure = $this->createCategory('Loisirs');

        $impact = $this->preview($restaurants['id'], 'MOVE', $leisure['id']);
        self::assertTrue(self::boolValue($impact, 'allowed'));
        self::assertSame(1, self::intValue($impact, 'descendantCount'));
        self::assertSame(3, self::intValue($impact, 'resultingDepth'));
        self::assertFalse(self::boolValue($impact, 'archivesSource'));
        self::assertNull($impact['affectedClassificationsReason']);
        self::assertSame(0, self::intValue($impact, 'affectedClassifications'));

        $this->move($restaurants['id'], $leisure['id'], $restaurants['version']);
        self::assertResponseIsSuccessful();
        $moved = $this->decode();
        self::assertSame($leisure['id'], $moved['parentId']);
        self::assertSame(2, self::intValue($moved, 'depth'));
        self::assertSame(3, $this->row($delivery['id'])['depth']);
    }

    public function testMovingIntoItsOwnBranchIsRefusedAsACycle(): void
    {
        $food = $this->createCategory('Alimentation');
        $restaurants = $this->createCategory('Restaurants', $food['id']);

        $impact = $this->preview($food['id'], 'MOVE', $restaurants['id']);
        self::assertFalse(self::boolValue($impact, 'allowed'));
        self::assertContains('CYCLE', self::stringList($impact, 'blockers'));

        $this->move($food['id'], $restaurants['id'], $food['version']);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('CYCLE', self::stringList($this->decode(), 'blockers'));
        self::assertNull($this->row($food['id'])['parentId']);
    }

    public function testAMoveThatWouldOverflowTheBoundedTreeIsRefused(): void
    {
        $deepestId = $this->createCategory('Niveau 1')['id'];
        for ($level = 2; $level <= Category::MAX_TREE_DEPTH; ++$level) {
            $deepestId = $this->createCategory('Niveau '.$level, $deepestId)['id'];
        }

        $branchRoot = $this->createCategory('Racine bis');
        $branchChild = $this->createCategory('Branche', $branchRoot['id']);

        $impact = $this->preview($branchRoot['id'], 'MOVE', $deepestId);
        self::assertFalse(self::boolValue($impact, 'allowed'));
        self::assertContains('DEPTH_EXCEEDED', self::stringList($impact, 'blockers'));
        self::assertSame(Category::MAX_TREE_DEPTH + 2, self::intValue($impact, 'resultingDepth'));

        $this->move($branchRoot['id'], $deepestId, $branchRoot['version']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, $this->row($branchChild['id'])['depth']);
    }

    public function testAMoveIsRefusedWhenTheLabelIsAlreadyTakenUnderTheNewParent(): void
    {
        $leisure = $this->createCategory('Loisirs');
        $this->createCategory('Restaurants', $leisure['id']);
        $restaurants = $this->createCategory('Restaurants');

        $impact = $this->preview($restaurants['id'], 'MOVE', $leisure['id']);
        self::assertContains('SIBLING_LABEL_CONFLICT', self::stringList($impact, 'blockers'));

        $this->move($restaurants['id'], $leisure['id'], $restaurants['version']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMergeReparentsTheChildrenArchivesTheSourceAndRecordsTheRedirection(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $delivery = $this->createCategory('Livraison', $restaurants['id']);
        $eatingOut = $this->createCategory('Sorties');

        $impact = $this->preview($restaurants['id'], 'MERGE', $eatingOut['id']);
        self::assertTrue(self::boolValue($impact, 'allowed'));
        self::assertSame(1, self::intValue($impact, 'reparentedChildCount'));
        self::assertTrue(self::boolValue($impact, 'archivesSource'));
        self::assertTrue(self::boolValue($impact, 'redirectsHistory'));

        $this->merge($restaurants['id'], $eatingOut['id'], $restaurants['version']);
        self::assertResponseIsSuccessful();
        $merged = $this->decode();
        self::assertNotNull($merged['archivedAt']);
        self::assertSame(
            ['kind' => 'MERGE', 'targetId' => $eatingOut['id'], 'targetLabel' => 'Sorties', 'effectiveFrom' => null],
            $merged['replacement'],
        );

        $moved = $this->row($delivery['id']);
        self::assertSame($eatingOut['id'], $moved['parentId']);
        self::assertSame(2, $moved['depth']);
    }

    public function testAMergeIntoItsOwnBranchOrAcrossTypesIsRefused(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $delivery = $this->createCategory('Livraison', $restaurants['id']);
        $salary = $this->createCategory('Salaire', type: 'INCOME');

        self::assertContains(
            'TARGET_IS_DESCENDANT',
            self::stringList($this->preview($restaurants['id'], 'MERGE', $delivery['id']), 'blockers'),
        );
        self::assertContains(
            'TARGET_TYPE_MISMATCH',
            self::stringList($this->preview($restaurants['id'], 'MERGE', $salary['id']), 'blockers'),
        );

        $this->merge($restaurants['id'], $delivery['id'], $restaurants['version']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->row($restaurants['id'])['archivedAt']);
    }

    public function testAMergeIsRefusedWhenAMovedChildWouldClashUnderTheTarget(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $this->createCategory('Livraison', $restaurants['id']);
        $eatingOut = $this->createCategory('Sorties');
        $this->createCategory('Livraison', $eatingOut['id']);

        self::assertContains(
            'SIBLING_LABEL_CONFLICT',
            self::stringList($this->preview($restaurants['id'], 'MERGE', $eatingOut['id']), 'blockers'),
        );
    }

    public function testArchivingWaitsForTheBranchAndNeverDeletesTheRow(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $delivery = $this->createCategory('Livraison', $restaurants['id']);

        self::assertContains(
            'ACTIVE_CHILDREN',
            self::stringList($this->preview($restaurants['id'], 'ARCHIVE'), 'blockers'),
        );
        $this->archive($restaurants['id'], $restaurants['version']);
        self::assertResponseStatusCodeSame(422);

        $this->archive($delivery['id'], $delivery['version']);
        self::assertResponseIsSuccessful();
        $this->archive($restaurants['id'], $restaurants['version']);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->decode()['archivedAt']);

        // Nothing hard-deletes a category: both rows are still there, archived.
        self::assertSame(2, $this->rowCount('category_categories'));
        $this->client->request('DELETE', '/api/v1/categories/'.$restaurants['id']);
        self::assertResponseStatusCodeSame(405);
    }

    public function testAnArchivedCategoryRefusesEveryFurtherOperation(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $this->archive($restaurants['id'], $restaurants['version']);
        self::assertResponseIsSuccessful();
        $archivedVersion = self::intValue($this->decode(), 'version');

        self::assertContains(
            'SOURCE_ARCHIVED',
            self::stringList($this->preview($restaurants['id'], 'MERGE', $eatingOut['id']), 'blockers'),
        );
        $this->merge($restaurants['id'], $eatingOut['id'], $archivedVersion);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('SOURCE_ARCHIVED', self::stringList($this->decode(), 'blockers'));

        self::assertContains(
            'TARGET_ARCHIVED',
            self::stringList($this->preview($eatingOut['id'], 'MERGE', $restaurants['id']), 'blockers'),
        );
    }

    public function testADatedReplacementKeepsTheSourceSelectableBeforeTheDate(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');

        $impact = $this->preview($restaurants['id'], 'REPLACE', $eatingOut['id'], '2026-10-01');
        self::assertTrue(self::boolValue($impact, 'allowed'));
        self::assertFalse(self::boolValue($impact, 'archivesSource'));
        self::assertTrue(self::boolValue($impact, 'redirectsHistory'));
        self::assertSame('2026-10-01', $impact['effectiveFrom']);

        $this->replace($restaurants['id'], $eatingOut['id'], '2026-10-01', $restaurants['version']);
        self::assertResponseIsSuccessful();
        $replaced = $this->decode();
        self::assertNull($replaced['archivedAt']);
        self::assertSame(
            [
                'kind' => 'REPLACEMENT',
                'targetId' => $eatingOut['id'],
                'targetLabel' => 'Sorties',
                'effectiveFrom' => '2026-10-01',
            ],
            $replaced['replacement'],
        );

        $this->client->request('GET', '/api/v1/categories');
        $listed = array_column($this->items(), 'replacement', 'id');
        self::assertNotNull($listed[$restaurants['id']] ?? null);
        self::assertNull($listed[$eatingOut['id']] ?? null);
    }

    public function testACategoryIsRedirectedOnceAndNeverInALoop(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $leisure = $this->createCategory('Loisirs');

        $this->replace($restaurants['id'], $eatingOut['id'], '2026-10-01', $restaurants['version']);
        self::assertResponseIsSuccessful();

        self::assertContains(
            'ALREADY_REDIRECTED',
            self::stringList($this->preview($restaurants['id'], 'REPLACE', $leisure['id'], '2026-11-01'), 'blockers'),
        );
        $this->replace($restaurants['id'], $leisure['id'], '2026-11-01', $restaurants['version']);
        self::assertResponseStatusCodeSame(422);

        self::assertContains(
            'REPLACEMENT_CYCLE',
            self::stringList($this->preview($eatingOut['id'], 'REPLACE', $restaurants['id'], '2026-11-01'), 'blockers'),
        );
        $this->replace($eatingOut['id'], $restaurants['id'], '2026-11-01', $eatingOut['version']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->rowCount('category_replacements'));
    }

    public function testAnInvalidReplacementDateIsRefusedBeforeAnythingIsRecorded(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');

        // February 31st rolls over silently in most date parsers; the bounds keep a
        // replacement from being anchored centuries away from any real history.
        $this->replace($restaurants['id'], $eatingOut['id'], '2026-02-31', $restaurants['version']);
        self::assertResponseStatusCodeSame(422);

        $this->replace($restaurants['id'], $eatingOut['id'], '1899-12-31', $restaurants['version']);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->rowCount('category_replacements'));
    }

    public function testAStaleConfirmationIsRefusedRatherThanApplied(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $this->connection->update(
            'category_categories',
            ['label' => 'Restaurants et bars', 'version' => 2],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $restaurants['id']],
        );

        $this->merge($restaurants['id'], $eatingOut['id'], $restaurants['version']);
        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->row($restaurants['id'])['archivedAt']);
    }

    public function testEveryOperationIsScopedToItsWorkspaceAndProtectedByCsrf(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000cf';
        $this->insertForeignCategory($foreignId);
        $own = $this->createCategory('Restaurants');

        $this->archive($foreignId, 1);
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();
        $this->archive('00000000-0000-7000-8000-0000000000ce', 1);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/categories/'.$foreignId.'/impact?operation=ARCHIVE');
        self::assertResponseStatusCodeSame(404);

        // A target outside the workspace must be indistinguishable from one that never existed.
        self::assertContains(
            'TARGET_NOT_FOUND',
            self::stringList($this->preview($own['id'], 'MERGE', $foreignId), 'blockers'),
        );

        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->archive($own['id'], $own['version']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->row($own['id'])['archivedAt']);
    }

    public function testALifecycleOperationAuditsStructureWithoutLeakingLabels(): void
    {
        $restaurants = $this->createCategory('Libellé confidentiel');
        $eatingOut = $this->createCategory('Autre libellé privé');
        $this->merge($restaurants['id'], $eatingOut['id'], $restaurants['version']);
        self::assertResponseIsSuccessful();

        $event = $this->connection->fetchAssociative(
            'SELECT actor_id, entity_id, after_json FROM audit_events'
            ." WHERE workspace_id = ? AND event_type = 'category.merged'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsArray($event);
        self::assertSame(WorkspaceFixture::OWNER_ID, $event['actor_id']);
        self::assertSame($restaurants['id'], $event['entity_id']);
        self::assertIsString($event['after_json']);
        self::assertStringContainsString($eatingOut['id'], $event['after_json']);
        self::assertStringNotContainsString('confidentiel', mb_strtolower($event['after_json']));
        self::assertStringNotContainsString('privé', mb_strtolower($event['after_json']));
    }

    public function testAnUnsupportedOperationOrMisplacedArgumentIsRejected(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $impactUrl = '/api/v1/categories/'.$restaurants['id'].'/impact';

        $this->client->request('GET', $impactUrl.'?operation=DELETE');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', $impactUrl.'?operation=ARCHIVE&targetId='.$eatingOut['id']);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', $impactUrl.'?operation=REPLACE&targetId='.$eatingOut['id']);
        self::assertResponseStatusCodeSame(400);

        // A body carrying a field its endpoint does not declare never reaches the use case.
        $this->client->request(
            'POST',
            '/api/v1/categories/'.$restaurants['id'].'/archive',
            server: self::jsonHeaders(),
            content: json_encode(['version' => 1, 'archivedAt' => '2020-01-01'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testACategoryOthersRedirectIntoCannotBeArchivedIntoADeadEnd(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $this->replace($restaurants['id'], $eatingOut['id'], '2026-10-01', $restaurants['version']);
        self::assertResponseIsSuccessful();

        $impact = $this->preview($eatingOut['id'], 'ARCHIVE');
        self::assertFalse(self::boolValue($impact, 'allowed'));
        self::assertSame(1, self::intValue($impact, 'incomingRedirectionCount'));
        self::assertContains('REDIRECTION_TARGET', self::stringList($impact, 'blockers'));

        $this->archive($eatingOut['id'], $eatingOut['version']);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('REDIRECTION_TARGET', self::stringList($this->decode(), 'blockers'));
        self::assertNull($this->row($eatingOut['id'])['archivedAt']);
    }

    public function testMergingARedirectionTargetCarriesTheRedirectionOverToTheNewTarget(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $leisure = $this->createCategory('Loisirs');
        $this->replace($restaurants['id'], $eatingOut['id'], '2026-10-01', $restaurants['version']);
        self::assertResponseIsSuccessful();

        $impact = $this->preview($eatingOut['id'], 'MERGE', $leisure['id']);
        self::assertTrue(self::boolValue($impact, 'allowed'));
        self::assertSame(1, self::intValue($impact, 'incomingRedirectionCount'));

        $this->merge($eatingOut['id'], $leisure['id'], $eatingOut['version']);
        self::assertResponseIsSuccessful();

        // The dated replacement now names the surviving category, not the archived one.
        $redirections = $this->connection->fetchAllAssociative(
            'SELECT source_category_id, target_category_id, kind, effective_from'
            .' FROM category_replacements WHERE workspace_id = ? ORDER BY kind',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertSame(
            [
                ['source_category_id' => $eatingOut['id'], 'target_category_id' => $leisure['id'], 'kind' => 'MERGE', 'effective_from' => null],
                ['source_category_id' => $restaurants['id'], 'target_category_id' => $leisure['id'], 'kind' => 'REPLACEMENT', 'effective_from' => '2026-10-01'],
            ],
            $redirections,
        );

        $json = $this->connection->fetchOne(
            "SELECT after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'category.merged'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsString($json);
        self::assertStringContainsString('repointedRedirections', $json);
    }

    public function testAChildCanBeFoldedIntoItsOwnParentUnderTheSameLabel(): void
    {
        $leisure = $this->createCategory('Loisirs');
        $sport = $this->createCategory('Sport', $leisure['id']);
        $indoorSport = $this->createCategory('Sport', $sport['id']);

        // Once the parent is archived by the merge it no longer occupies the label, so
        // the grandchild taking its place under the root is not a sibling collision.
        $impact = $this->preview($sport['id'], 'MERGE', $leisure['id']);
        self::assertTrue(self::boolValue($impact, 'allowed'));
        self::assertNotContains('SIBLING_LABEL_CONFLICT', self::stringList($impact, 'blockers'));

        $this->merge($sport['id'], $leisure['id'], $sport['version']);
        self::assertResponseIsSuccessful();

        $moved = $this->row($indoorSport['id']);
        self::assertSame($leisure['id'], $moved['parentId']);
        self::assertSame(2, $moved['depth']);
        self::assertNotNull($this->row($sport['id'])['archivedAt']);
    }

    public function testAMergePreviewCountsTheArchivedSubcategoriesItWouldAlsoMove(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $retired = $this->createCategory('Sushi', $restaurants['id']);
        $eatingOut = $this->createCategory('Sorties');
        $this->archive($retired['id'], $retired['version']);
        self::assertResponseIsSuccessful();

        $impact = $this->preview($restaurants['id'], 'MERGE', $eatingOut['id']);
        self::assertSame(1, self::intValue($impact, 'descendantCount'));
        self::assertSame(1, self::intValue($impact, 'archivedDescendantCount'));
        self::assertSame(1, self::intValue($impact, 'reparentedChildCount'));

        $this->merge($restaurants['id'], $eatingOut['id'], $restaurants['version']);
        self::assertResponseIsSuccessful();

        // Archived history follows its ancestor rather than being stranded at a depth
        // its parent no longer has.
        $moved = $this->row($retired['id']);
        self::assertSame($eatingOut['id'], $moved['parentId']);
        self::assertSame(2, $moved['depth']);
        self::assertNotNull($moved['archivedAt']);
    }

    public function testMovingACategoryDuringAnActiveClosureIsRefused(): void
    {
        $food = $this->createCategory('Alimentation');
        $restaurants = $this->createCategory('Restaurants', $food['id']);
        $leisure = $this->createCategory('Loisirs');
        $this->closeMonthInDatabase($this->connection, 2026, 3);

        $this->move($restaurants['id'], $leisure['id'], $restaurants['version']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $unchanged = $this->row($restaurants['id']);
        self::assertSame($food['id'], $unchanged['parentId']);
    }

    public function testMergingACategoryDuringAnActiveClosureIsRefused(): void
    {
        $restaurants = $this->createCategory('Restaurants');
        $eatingOut = $this->createCategory('Sorties');
        $this->closeMonthInDatabase($this->connection, 2026, 3);

        $this->merge($restaurants['id'], $eatingOut['id'], $restaurants['version']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $unchanged = $this->row($restaurants['id']);
        self::assertNull($unchanged['archivedAt']);
    }

    /** @return array<string, mixed> */
    private function preview(
        string $id,
        string $operation,
        ?string $targetId = null,
        ?string $effectiveFrom = null,
    ): array {
        $query = ['operation' => $operation];
        if (null !== $targetId) {
            $query['targetId'] = $targetId;
        }
        if (null !== $effectiveFrom) {
            $query['effectiveFrom'] = $effectiveFrom;
        }

        $this->client->request('GET', '/api/v1/categories/'.$id.'/impact?'.http_build_query($query));
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    private function move(string $id, ?string $parentId, int $version): void
    {
        $this->post($id, 'move', ['parentId' => $parentId, 'version' => $version]);
    }

    private function merge(string $id, ?string $targetId, int $version): void
    {
        $this->post($id, 'merge', ['targetId' => $targetId, 'version' => $version]);
    }

    private function archive(string $id, int $version): void
    {
        $this->post($id, 'archive', ['version' => $version]);
    }

    private function replace(string $id, ?string $targetId, string $effectiveFrom, int $version): void
    {
        $this->post($id, 'replacement', [
            'targetId' => $targetId,
            'effectiveFrom' => $effectiveFrom,
            'version' => $version,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $id, string $operation, array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/categories/'.$id.'/'.$operation,
            server: self::jsonHeaders(),
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{id: string, version: int} */
    private function createCategory(string $label, ?string $parentId = null, string $type = 'EXPENSE'): array
    {
        $this->client->request(
            'POST',
            '/api/v1/categories',
            server: self::jsonHeaders(),
            content: json_encode([
                'type' => $type,
                'label' => $label,
                'parentId' => $parentId,
                'icon' => null,
                'color' => null,
                'defaultAnalyticAxes' => [],
                'budgetIncluded' => true,
                'sortOrder' => 0,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode();

        return ['id' => self::stringValue($created, 'id'), 'version' => self::intValue($created, 'version')];
    }

    /** @return array{parentId: ?string, depth: int, archivedAt: ?string} */
    private function row(string $id): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT parent_id, depth, archived_at FROM category_categories WHERE workspace_id = ? AND id = ?',
            [WorkspaceFixture::OWN_WORKSPACE, $id],
        );
        self::assertIsArray($row);
        $parentId = $row['parent_id'];
        $archivedAt = $row['archived_at'];
        self::assertTrue(null === $parentId || is_string($parentId));
        self::assertTrue(null === $archivedAt || is_string($archivedAt));
        self::assertIsNumeric($row['depth']);

        return [
            'parentId' => is_string($parentId) ? $parentId : null,
            'depth' => (int) $row['depth'],
            'archivedAt' => is_string($archivedAt) ? $archivedAt : null,
        ];
    }

    private function rowCount(string $table): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM '.$table.' WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function insertForeignCategory(string $id): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id,
            'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'type' => 'EXPENSE',
            'label' => 'Private label',
            'parent_id' => null,
            'icon' => null,
            'color' => null,
            'default_analytic_axes' => '[]',
            'budget_included' => true,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
        ]);
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

    /** @param array<string, mixed> $data */
    private static function boolValue(array $data, string $key): bool
    {
        self::assertArrayHasKey($key, $data);
        self::assertIsBool($data[$key]);

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        self::assertArrayHasKey($key, $data);
        $values = $data[$key];
        self::assertIsList($values);

        $strings = [];
        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
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

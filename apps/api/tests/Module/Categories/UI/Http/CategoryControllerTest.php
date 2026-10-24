<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\ClosesPeriods;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CategoryControllerTest extends WebTestCase
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

    public function testTheOwnerCreatesAParentAndChildWithAnalyticDefaults(): void
    {
        $parent = $this->createCategory(label: 'Alimentation');
        $child = $this->createCategory(label: 'Restaurants', parentId: self::stringValue($parent, 'id'));

        self::assertSame('EXPENSE', $child['type']);
        self::assertSame(self::stringValue($parent, 'id'), $child['parentId']);
        self::assertSame('Alimentation', $child['parentLabel']);
        self::assertSame(['ESSENTIAL', 'VARIABLE'], $child['defaultAnalyticAxes']);
        self::assertSame(2, $child['depth']);

        $this->client->request('GET', '/api/v1/categories');
        self::assertResponseIsSuccessful();
        self::assertSame(['Alimentation', 'Restaurants'], array_column($this->items(), 'label'));
    }

    public function testAListedChildNamesItsParentEvenOutsideThePage(): void
    {
        $parent = $this->createCategory(label: 'Alimentation');
        $this->createCategory(label: 'Restaurants', parentId: self::stringValue($parent, 'id'));

        $this->client->request('GET', '/api/v1/categories?page=2&pageSize=1');
        self::assertResponseIsSuccessful();
        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame('Restaurants', $items[0]['label']);
        self::assertSame('Alimentation', $items[0]['parentLabel']);
    }

    public function testAnArchivedCategoryIsReportedAsReadOnlyRatherThanConflicting(): void
    {
        $category = $this->createCategory(label: 'Restaurants');
        $id = self::stringValue($category, 'id');
        $this->connection->executeStatement(
            'UPDATE category_categories SET archived_at = now() WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );
        // The archive frees the label, so an active namesake exists while the archived row is edited.
        $this->createCategory(label: 'Restaurants');

        $this->requestUpdate($id, $category);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnActiveSiblingLabelIsUniqueIgnoringCase(): void
    {
        $this->createCategory(label: 'Restaurants');

        $this->requestCreate(label: 'restaurants');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertStringNotContainsString('restaurants', mb_strtolower((string) $this->client->getResponse()->getContent()));
    }

    public function testSiblingNormalizationIsDatabaseAuthoritativeForUnicodeLabels(): void
    {
        $this->createCategory(label: 'İ');
        $this->requestCreate(label: 'i');
        self::assertResponseStatusCodeSame(409);

        $this->createCategory(label: 'Épargne');
        $this->requestCreate(label: 'épargne');
        self::assertResponseStatusCodeSame(409);

        $expandedLowercase = str_repeat('İ', 80);
        $category = $this->createCategory(label: $expandedLowercase);
        self::assertSame($expandedLowercase, $category['label']);
    }

    public function testAParentFromAnotherWorkspaceIsRejectedWithoutDisclosure(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000cf';
        $this->insertCategory($foreignId, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');

        $this->requestCreate(label: 'Child', parentId: $foreignId);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('Private label', (string) $this->client->getResponse()->getContent());
        $ownCount = $this->connection->fetchOne(
            'SELECT count(*) FROM category_categories WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($ownCount) || is_string($ownCount));
        self::assertSame(0, (int) $ownCount);
    }

    public function testAMalformedParentAndMassAssignmentAreRejected(): void
    {
        $this->requestCreate(label: 'Child', parentId: 'not-a-uuid');
        self::assertResponseStatusCodeSame(422);

        $body = $this->payload('Child');
        $body['workspaceId'] = WorkspaceFixture::OTHER_WORKSPACE;
        $this->client->request('POST', '/api/v1/categories', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAUsedCategoryCannotChangeType(): void
    {
        $category = $this->createCategory(label: 'Restaurants');
        $id = self::stringValue($category, 'id');
        $this->connection->update(
            'category_categories',
            ['used_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );

        $this->requestUpdate($id, [...$category, 'type' => 'INCOME']);

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/v1/categories');
        $used = $this->items()[0];
        self::assertFalse($used['typeEditable']);
        self::assertSame('USED', $used['typeEditReason']);
    }

    public function testAParentWithChildrenCannotChangeTypeSilently(): void
    {
        $parent = $this->createCategory(label: 'Alimentation');
        $this->createCategory(label: 'Restaurants', parentId: self::stringValue($parent, 'id'));

        $this->requestUpdate(self::stringValue($parent, 'id'), [...$parent, 'type' => 'INCOME']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEligibilityExplainsTreeRestrictionsAndBoundsParentSearch(): void
    {
        $levels = [];
        $parentId = null;
        for ($depth = 1; $depth <= 8; ++$depth) {
            $levels[] = $this->createCategory(label: 'Level '.$depth, parentId: $parentId);
            $parentId = self::stringValue($levels[$depth - 1], 'id');
        }

        self::assertFalse($levels[7]['canAcceptChildren']);
        self::assertFalse($levels[1]['typeEditable']);
        self::assertSame('CHILD', $levels[1]['typeEditReason']);

        $this->client->request('GET', '/api/v1/categories?type=EXPENSE&search=Level&parentEligible=true&pageSize=50');
        self::assertResponseIsSuccessful();
        $eligible = $this->items();
        self::assertCount(7, $eligible);
        self::assertNotContains('Level 8', array_column($eligible, 'label'));
        self::assertFalse($eligible[0]['typeEditable']);
        self::assertSame('HAS_CHILDREN', $eligible[0]['typeEditReason']);
    }

    public function testAnUpdateUsesOptimisticVersioning(): void
    {
        $category = $this->createCategory(label: 'Restaurants');
        $id = self::stringValue($category, 'id');
        $this->requestUpdate($id, [...$category, 'label' => 'Sorties']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);

        $this->requestUpdate($id, [...$category, 'label' => 'Ancien onglet']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAForeignCategoryAnswersExactlyLikeAnUnknownOne(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000cf';
        $this->insertCategory($foreignId, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');

        $body = $this->updatePayload('Changed');
        $this->requestUpdate($foreignId, $body);
        self::assertResponseStatusCodeSame(404);
        $foreign = (string) $this->client->getResponse()->getContent();

        $this->requestUpdate('00000000-0000-7000-8000-0000000000ce', $body);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreign, (string) $this->client->getResponse()->getContent());
    }

    public function testArchivedRowsAreExplicitlyRequestedAndReadOnly(): void
    {
        $category = $this->createCategory(label: 'Ancienne catégorie');
        $id = self::stringValue($category, 'id');
        $this->connection->update(
            'category_categories',
            ['archived_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $id],
        );

        $this->client->request('GET', '/api/v1/categories');
        self::assertSame([], $this->items());

        $this->client->request('GET', '/api/v1/categories?includeArchived=true');
        self::assertCount(1, $this->items());
        self::assertNotNull($this->items()[0]['archivedAt']);

        $this->requestUpdate($id, $category);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMutationsRequireCsrfAndWriteARedactedAuditEvent(): void
    {
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $this->requestCreate(label: 'Secret household label');
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $category = $this->createCategory(label: 'Secret household label');

        $event = $this->connection->fetchAssociative(
            "SELECT actor_id, event_type, entity_id, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'category.created'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsArray($event);
        self::assertSame(WorkspaceFixture::OWNER_ID, $event['actor_id']);
        self::assertSame($category['id'], $event['entity_id']);
        self::assertIsString($event['after_json']);
        self::assertStringNotContainsString('Secret household label', $event['after_json']);
    }

    public function testAnUpdateAuditsOnlySafeChangeIndicators(): void
    {
        $category = $this->createCategory(label: 'Secret household label');
        $this->requestUpdate(self::stringValue($category, 'id'), [
            ...$category,
            'label' => 'Another private label',
            'icon' => 'home',
        ]);
        self::assertResponseIsSuccessful();

        $json = $this->connection->fetchOne(
            "SELECT after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'category.updated'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsString($json);
        $after = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($after);
        self::assertSame('true', $after['displayNameChanged'] ?? null);
        self::assertSame('true', $after['iconChanged'] ?? null);
        self::assertArrayNotHasKey('label', $after);
        self::assertStringNotContainsString('private label', mb_strtolower($json));
    }

    public function testMutationPayloadAndAnalyticAxisCountsAreBounded(): void
    {
        $tooManyAxes = $this->payload('Restaurants');
        $tooManyAxes['defaultAnalyticAxes'] = [
            'ESSENTIAL', 'DISCRETIONARY', 'FIXED', 'VARIABLE', 'PERSONAL', 'PROFESSIONAL', 'EXTRA',
        ];
        $this->client->request(
            'POST',
            '/api/v1/categories',
            server: self::jsonHeaders(),
            content: json_encode($tooManyAxes, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);

        $oversized = $this->payload(str_repeat('x', 17_000));
        $this->client->request(
            'POST',
            '/api/v1/categories',
            server: self::jsonHeaders(),
            content: json_encode($oversized, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(413);
    }

    public function testTogglingBudgetIncludedDuringAnActiveClosureIsRefused(): void
    {
        $category = $this->createCategory('Restaurants');
        $this->closeMonthInDatabase($this->connection, 2026, 3);

        $toggled = $category;
        $toggled['budgetIncluded'] = false;
        $this->requestUpdate(self::stringValue($category, 'id'), $toggled);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        $row = $this->connection->fetchAssociative(
            'SELECT budget_included, version FROM category_categories WHERE id = ?',
            [self::stringValue($category, 'id')],
        );
        self::assertIsArray($row);
        self::assertTrue((bool) $row['budget_included']);
        $version = $row['version'];
        self::assertTrue(is_int($version) || is_string($version));
        self::assertSame(1, (int) $version);
    }

    public function testEditingAFieldOtherThanBudgetIncludedDuringAnActiveClosureStillWorks(): void
    {
        $category = $this->createCategory('Restaurants');
        $this->closeMonthInDatabase($this->connection, 2026, 3);

        $edited = $category;
        $edited['label'] = 'Restaurants et bars';
        $this->requestUpdate(self::stringValue($category, 'id'), $edited);

        self::assertResponseIsSuccessful();
        self::assertSame('Restaurants et bars', $this->decode()['label']);
    }

    public function testAChildWithoutColorInheritsTheNearestAncestorColorWhileStoringNull(): void
    {
        $root = $this->createColored('Maison', null, '#112233');
        $mid = $this->createColored('Energie', self::stringValue($root, 'id'), null);
        $leaf = $this->createColored('Electricite', self::stringValue($mid, 'id'), null);
        $own = $this->createColored('Gaz', self::stringValue($mid, 'id'), '#445566');

        self::assertSame('#112233', $root['effectiveColor']);
        self::assertNull($mid['color']);
        self::assertSame('#112233', $mid['effectiveColor']);
        self::assertNull($leaf['color']);
        self::assertSame('#112233', $leaf['effectiveColor']);
        self::assertSame('#445566', $own['effectiveColor']);

        $this->client->request('GET', '/api/v1/categories');
        $byLabel = array_column($this->items(), null, 'label');
        self::assertSame('#112233', $byLabel['Electricite']['effectiveColor']);
        self::assertNull($byLabel['Electricite']['color']);
    }

    public function testChangingOrClearingAnAncestorColorOnlyAffectsInheritingDescendants(): void
    {
        $root = $this->createColored('Maison', null, '#112233');
        $this->createColored('Energie', self::stringValue($root, 'id'), null);
        $this->createColored('Gaz', self::stringValue($root, 'id'), '#445566');

        $this->requestUpdate(self::stringValue($root, 'id'), ['color' => '#AA0000'] + $root);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/categories');
        $byLabel = array_column($this->items(), null, 'label');
        self::assertSame('#AA0000', $byLabel['Energie']['effectiveColor']);
        self::assertSame('#445566', $byLabel['Gaz']['effectiveColor']);
        self::assertNull($byLabel['Energie']['color']);

        $this->requestUpdate(self::stringValue($root, 'id'), ['color' => null, 'version' => $this->version($root, 1)] + $root);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/categories');
        $byLabel = array_column($this->items(), null, 'label');
        self::assertNull($byLabel['Maison']['effectiveColor']);
        self::assertNull($byLabel['Energie']['effectiveColor']);
        self::assertSame('#445566', $byLabel['Gaz']['effectiveColor']);
    }

    public function testClearingAChildColorRevealsTheAncestorColorImmediately(): void
    {
        $root = $this->createColored('Maison', null, '#112233');
        $child = $this->createColored('Gaz', self::stringValue($root, 'id'), '#445566');

        $this->requestUpdate(self::stringValue($child, 'id'), ['color' => null] + $child);
        self::assertResponseIsSuccessful();
        $cleared = $this->decode();

        self::assertNull($cleared['color']);
        self::assertSame('#112233', $cleared['effectiveColor']);
    }

    public function testMovingACategoryRecalculatesTheInheritedColorOfItsSubtree(): void
    {
        $red = $this->createColored('Rouge', null, '#AA0000');
        $blue = $this->createColored('Bleu', null, '#0000AA');
        $branch = $this->createColored('Branche', self::stringValue($red, 'id'), null);
        $this->createColored('Feuille', self::stringValue($branch, 'id'), null);

        $this->client->request(
            'POST',
            '/api/v1/categories/'.self::stringValue($branch, 'id').'/move',
            server: self::jsonHeaders(),
            content: json_encode(['parentId' => self::stringValue($blue, 'id'), 'version' => $branch['version']], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $moved = $this->decode();
        self::assertNull($moved['color']);
        self::assertSame('#0000AA', $moved['effectiveColor']);

        $this->client->request('GET', '/api/v1/categories');
        $byLabel = array_column($this->items(), null, 'label');
        self::assertSame('#0000AA', $byLabel['Feuille']['effectiveColor']);
        self::assertNull($byLabel['Feuille']['color']);
    }

    public function testAnArchivedAncestorStillProvidesItsColorToActiveDescendants(): void
    {
        $root = $this->createColored('Maison', null, '#112233');
        $this->createColored('Gaz', self::stringValue($root, 'id'), null);
        $this->connection->update(
            'category_categories',
            ['archived_at' => '2026-09-01 12:00:00+00'],
            ['workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'id' => $root['id']],
        );

        $this->client->request('GET', '/api/v1/categories');
        self::assertSame('#112233', $this->items()[0]['effectiveColor'] ?? null);
        self::assertSame([], array_filter($this->items(), static fn (array $item): bool => 'Maison' === $item['label']));
    }

    public function testIconsInheritLikeColorsWithoutBeingStored(): void
    {
        $root = $this->createCategoryWith('Maison', null, ['icon' => 'home']);
        $mid = $this->createCategoryWith('Energie', self::stringValue($root, 'id'), ['icon' => null]);
        $leaf = $this->createCategoryWith('Electricite', self::stringValue($mid, 'id'), ['icon' => null]);
        $own = $this->createCategoryWith('Gaz', self::stringValue($mid, 'id'), ['icon' => 'flame']);

        self::assertNull($leaf['icon']);
        self::assertSame('home', $leaf['effectiveIcon']);
        self::assertSame('flame', $own['effectiveIcon']);

        // Clearing the child's own icon reveals the ancestor's immediately.
        $this->requestUpdate(self::stringValue($own, 'id'), ['icon' => null] + $own);
        self::assertResponseIsSuccessful();
        self::assertSame('home', $this->decode()['effectiveIcon']);

        // Changing the ancestor refreshes only inheriting descendants.
        $this->requestUpdate(self::stringValue($root, 'id'), ['icon' => 'building'] + $root);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/categories');
        $byLabel = array_column($this->items(), null, 'label');
        self::assertSame('building', $byLabel['Electricite']['effectiveIcon']);
        self::assertNull($byLabel['Electricite']['icon']);
    }

    public function testMovingACategoryRecalculatesTheInheritedIconOfItsSubtree(): void
    {
        $first = $this->createCategoryWith('Premier', null, ['icon' => 'home']);
        $second = $this->createCategoryWith('Second', null, ['icon' => 'car']);
        $branch = $this->createCategoryWith('Branche', self::stringValue($first, 'id'), ['icon' => null]);
        $this->createCategoryWith('Feuille', self::stringValue($branch, 'id'), ['icon' => null]);

        $this->client->request(
            'POST',
            '/api/v1/categories/'.self::stringValue($branch, 'id').'/move',
            server: self::jsonHeaders(),
            content: json_encode(['parentId' => self::stringValue($second, 'id'), 'version' => $branch['version']], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/categories');
        $byLabel = array_column($this->items(), null, 'label');
        self::assertSame('car', $byLabel['Feuille']['effectiveIcon']);
        self::assertNull($byLabel['Feuille']['icon']);
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
    private function createCategory(string $label, ?string $parentId = null): array
    {
        $this->requestCreate($label, $parentId);
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    private function requestCreate(string $label, ?string $parentId = null): void
    {
        $this->client->request(
            'POST',
            '/api/v1/categories',
            server: self::jsonHeaders(),
            content: json_encode($this->payload($label, $parentId), JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $category */
    private function requestUpdate(string $id, array $category): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/categories/'.$id,
            server: self::jsonHeaders(),
            content: json_encode([
                'type' => $category['type'],
                'label' => $category['label'],
                'icon' => $category['icon'],
                'color' => $category['color'],
                'defaultAnalyticAxes' => $category['defaultAnalyticAxes'],
                'budgetIncluded' => $category['budgetIncluded'],
                'sortOrder' => $category['sortOrder'],
                'version' => $category['version'],
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function createColored(string $label, ?string $parentId, ?string $color): array
    {
        $this->client->request(
            'POST',
            '/api/v1/categories',
            server: self::jsonHeaders(),
            content: json_encode(['color' => $color] + $this->payload($label, $parentId), JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createCategoryWith(string $label, ?string $parentId, array $overrides): array
    {
        $this->client->request(
            'POST',
            '/api/v1/categories',
            server: self::jsonHeaders(),
            content: json_encode($overrides + ['color' => null] + $this->payload($label, $parentId), JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $category */
    private function version(array $category, int $increment): int
    {
        $version = $category['version'];
        self::assertIsInt($version);

        return $version + $increment;
    }

    /** @return array<string, mixed> */
    private function payload(string $label, ?string $parentId = null): array
    {
        return [
            'type' => 'EXPENSE',
            'label' => $label,
            'parentId' => $parentId,
            'icon' => 'utensils',
            'color' => '#AABBCC',
            'defaultAnalyticAxes' => ['ESSENTIAL', 'VARIABLE'],
            'budgetIncluded' => true,
            'sortOrder' => 10,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(string $label): array
    {
        return [
            'type' => 'EXPENSE',
            'label' => $label,
            'icon' => 'utensils',
            'color' => '#AABBCC',
            'defaultAnalyticAxes' => ['ESSENTIAL'],
            'budgetIncluded' => true,
            'sortOrder' => 10,
            'version' => 1,
        ];
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

    private function insertCategory(string $id, string $workspaceId, string $label): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'type' => 'EXPENSE',
            'label' => $label,
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

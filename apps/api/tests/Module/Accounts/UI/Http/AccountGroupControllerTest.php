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

final class AccountGroupControllerTest extends WebTestCase
{
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

    public function testColorNormalizesPersistsResetsAndStaysStableAfterReorder(): void
    {
        $group = $this->createGroup('Empty group');
        self::assertNull($group['color']);
        self::assertIsString($group['resolvedColor']);
        self::assertSame($group['defaultColor'], $group['resolvedColor']);
        $id = self::stringValue($group, 'id');
        $this->requestUpdate($id, [...$group, 'color' => '#aabbcc']);
        self::assertResponseIsSuccessful();
        $updated = $this->decode();
        self::assertSame('#AABBCC', $updated['color']);
        self::assertSame('#AABBCC', $updated['resolvedColor']);
        $this->requestUpdate($id, [...$updated, 'color' => null, 'sortOrder' => 42, 'label' => 'Renamed']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->decode()['color']);
        $this->client->request('GET', '/api/v1/account-groups');
        self::assertSame($group['resolvedColor'], $this->items()[0]['resolvedColor']);
    }

    public function testAReorderWithoutColorPreservesTheExistingAssignment(): void
    {
        $group = $this->createGroup('Group');
        $id = self::stringValue($group, 'id');
        $this->requestUpdate($id, [...$group, 'color' => '#000000']);
        self::assertResponseIsSuccessful();
        $this->client->request('PUT', '/api/v1/account-groups/'.$id, server: self::jsonHeaders(), content: json_encode([
            'label' => 'Renamed', 'parentId' => null, 'sortOrder' => 42, 'version' => 2,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('#000000', $this->decode()['color']);
    }

    public function testCreationAcceptsDuplicateColorsAndRejectsInvalidColorWithAFieldError(): void
    {
        foreach (['First', 'Second'] as $label) {
            $this->client->request('POST', '/api/v1/account-groups', server: self::jsonHeaders(), content: json_encode([
                ...$this->payload($label), 'color' => '#aabbcc',
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            self::assertSame('#AABBCC', $this->decode()['color']);
        }
        $this->client->request('POST', '/api/v1/account-groups', server: self::jsonHeaders(), content: json_encode([
            ...$this->payload('Invalid'), 'color' => 'red',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'color', 'code' => 'invalid_color']], $this->decode()['errors']);
        self::assertSame(2, $this->ownGroupCount());
    }

    public function testInvalidColorReturnsAFieldErrorWithoutWriting(): void
    {
        $group = $this->createGroup('Empty group');
        foreach (['url(https://example.org)', '#123', 42, ['#000000']] as $color) {
            $this->requestUpdate(self::stringValue($group, 'id'), [...$group, 'color' => $color]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame([['field' => 'color', 'code' => 'invalid_color']], $this->decode()['errors']);
        }
        $this->client->request('GET', '/api/v1/account-groups');
        self::assertSame(1, $this->items()[0]['version']);
    }

    public function testTheOwnerCreatesAParentAndChildAndListsThem(): void
    {
        $parent = $this->createGroup(label: 'Épargne');
        $child = $this->createGroup(label: 'Livret', parentId: self::stringValue($parent, 'id'));

        self::assertSame(self::stringValue($parent, 'id'), $child['parentId']);
        self::assertSame('Épargne', $child['parentLabel']);
        self::assertSame(2, $child['depth']);
        $share = $child['share'];
        self::assertIsArray($share);
        self::assertSame([
            'ratio' => null,
            'percent' => null,
            'percentDisplay' => null,
            'reason' => 'MISSING_VALUATION',
        ], $share);
        self::assertNotSame('0', $share['percent']);
        self::assertNotSame('0%', $share['percent']);

        $this->client->request('GET', '/api/v1/account-groups');
        self::assertResponseIsSuccessful();
        self::assertSame(['Épargne', 'Livret'], array_column($this->items(), 'label'));
    }

    public function testAShareIsNeverPublishedAsZeroPercent(): void
    {
        $group = $this->createGroup(label: 'Liquidités');

        $share = $group['share'];
        self::assertIsArray($share);
        self::assertNull($share['ratio']);
        self::assertNull($share['percent']);
        self::assertSame('MISSING_VALUATION', $share['reason']);
    }

    public function testAnActiveSiblingLabelIsUniqueIgnoringCase(): void
    {
        $this->createGroup(label: 'Épargne');

        $this->requestCreate(label: 'épargne');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertStringNotContainsString('épargne', mb_strtolower((string) $this->client->getResponse()->getContent()));
    }

    public function testAParentFromAnotherWorkspaceIsRejectedWithoutDisclosure(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000cf';
        $this->insertGroup($foreignId, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');

        $this->requestCreate(label: 'Child', parentId: $foreignId);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('Private label', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->ownGroupCount());
    }

    public function testACycleIsRejectedOnUpdate(): void
    {
        $parent = $this->createGroup(label: 'Épargne');
        $child = $this->createGroup(label: 'Livret', parentId: self::stringValue($parent, 'id'));

        $this->requestUpdate(self::stringValue($parent, 'id'), [
            ...$parent,
            'parentId' => self::stringValue($child, 'id'),
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/v1/account-groups');
        $items = $this->items();
        self::assertNull($items[0]['parentId']);
        self::assertSame(self::stringValue($parent, 'id'), $items[1]['parentId']);
    }

    public function testAnUpdateUsesOptimisticVersioning(): void
    {
        $group = $this->createGroup(label: 'Épargne');
        $id = self::stringValue($group, 'id');
        $this->requestUpdate($id, [...$group, 'label' => 'Liquidités', 'color' => '#000000']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);
        self::assertSame('#000000', $this->decode()['color']);

        $this->requestUpdate($id, [...$group, 'label' => 'Ancien onglet', 'color' => '#FFFFFF']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAForeignGroupAnswersExactlyLikeAnUnknownOne(): void
    {
        $foreignId = '00000000-0000-7000-8000-0000000000cf';
        $this->insertGroup($foreignId, WorkspaceFixture::OTHER_WORKSPACE, 'Private label');

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
        $group = $this->createGroup(label: 'Ancien groupe');
        $id = self::stringValue($group, 'id');
        $this->requestArchive($id, self::intValue($group, 'version'));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/account-groups');
        self::assertSame([], $this->items());

        $this->client->request('GET', '/api/v1/account-groups?includeArchived=true');
        self::assertCount(1, $this->items());
        self::assertNotNull($this->items()[0]['archivedAt']);

        $this->requestUpdate($id, [...$group, 'version' => 2]);
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
        $group = $this->createGroup(label: 'Secret household label');

        $event = $this->connection->fetchAssociative(
            "SELECT actor_id, event_type, entity_id, after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'account_group.created'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsArray($event);
        self::assertSame(WorkspaceFixture::OWNER_ID, $event['actor_id']);
        self::assertSame($group['id'], $event['entity_id']);
        self::assertIsString($event['after_json']);
        self::assertStringNotContainsString('Secret household label', $event['after_json']);
        self::assertStringNotContainsString('label', mb_strtolower($event['after_json']));
    }

    public function testAnUpdateAuditsOnlySafeChangeIndicators(): void
    {
        $group = $this->createGroup(label: 'Secret household label');
        $this->requestUpdate(self::stringValue($group, 'id'), [
            ...$group,
            'label' => 'Another private label',
        ]);
        self::assertResponseIsSuccessful();

        $json = $this->connection->fetchOne(
            "SELECT after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'account_group.updated'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertIsString($json);
        $after = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($after);
        self::assertArrayHasKey('hasParent', $after);
        self::assertArrayHasKey('parentChanged', $after);
        self::assertArrayNotHasKey('label', $after);
        self::assertStringNotContainsString('private label', mb_strtolower($json));
    }

    public function testQueryBoundsAndPayloadSizeAreEnforced(): void
    {
        $this->client->request('GET', '/api/v1/account-groups?pageSize=0');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/account-groups?pageSize=101');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/account-groups?page=1001');
        self::assertResponseStatusCodeSame(400);

        $oversized = $this->payload(str_repeat('x', 17_000));
        $this->client->request(
            'POST',
            '/api/v1/account-groups',
            server: self::jsonHeaders(),
            content: json_encode($oversized, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(413);
    }

    public function testEachColorModeRoundTripsWithItsEffectiveColor(): void
    {
        $root = $this->createGroup('Root');
        self::assertSame('AUTO', $root['colorMode']);
        self::assertNull($root['parentResolvedColor']);
        $this->requestRaw('PUT', '/api/v1/account-groups/'.self::stringValue($root, 'id'), [
            'label' => 'Root', 'parentId' => null, 'sortOrder' => 0, 'version' => 1, 'colorMode' => 'CUSTOM', 'color' => '#486df0',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('CUSTOM', $this->decode()['colorMode']);
        self::assertSame('#486DF0', $this->decode()['resolvedColor']);

        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'First', 'parentId' => $root['id'], 'sortOrder' => 0]);
        $first = $this->decode();
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'Second', 'parentId' => $root['id'], 'sortOrder' => 1, 'colorMode' => 'AUTO']);
        $second = $this->decode();
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'Third', 'parentId' => $root['id'], 'sortOrder' => 2, 'colorMode' => 'PARENT']);
        $third = $this->decode();

        self::assertSame('#7B96F4', $first['resolvedColor']);
        self::assertSame('#113BCF', $second['resolvedColor']);
        self::assertSame('#486DF0', $second['parentResolvedColor']);
        self::assertSame('PARENT', $third['colorMode']);
        self::assertSame('#486DF0', $third['resolvedColor']);
        self::assertSame($third['defaultColor'], '#B8C7F9');

        // A custom parent change is seen by inheriting children without writing them.
        $this->requestRaw('PUT', '/api/v1/account-groups/'.self::stringValue($root, 'id'), [
            'label' => 'Root', 'parentId' => null, 'sortOrder' => 0, 'version' => 2, 'colorMode' => 'CUSTOM', 'color' => '#803078',
        ]);
        $this->client->request('GET', '/api/v1/account-groups');
        $listed = array_values(array_filter($this->items(), static fn (array $item): bool => $item['id'] === $first['id']));
        self::assertSame('#D897D2', $listed[0]['resolvedColor']);
        self::assertSame(1, $listed[0]['version']);
    }

    public function testLegacyPayloadsKeepTheirMeaning(): void
    {
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'Legacy', 'parentId' => null, 'sortOrder' => 0, 'color' => '#aabbcc']);
        self::assertSame('CUSTOM', $this->decode()['colorMode']);
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'Plain', 'parentId' => null, 'sortOrder' => 0, 'color' => null]);
        self::assertSame('AUTO', $this->decode()['colorMode']);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, list<array{field: string, code: string}>}> */
    public static function colorModeErrors(): iterable
    {
        yield 'unknown mode' => [['colorMode' => 'RAINBOW'], false, [['field' => 'colorMode', 'code' => 'invalid_choice']]];
        yield 'numeric mode' => [['colorMode' => 3], false, [['field' => 'colorMode', 'code' => 'invalid_choice']]];
        yield 'parent on root' => [['colorMode' => 'PARENT'], false, [['field' => 'colorMode', 'code' => 'parent_mode_on_root']]];
        yield 'custom without color' => [['colorMode' => 'CUSTOM'], true, [['field' => 'color', 'code' => 'required_for_custom']]];
        yield 'auto with color' => [['colorMode' => 'AUTO', 'color' => '#123456'], true, [['field' => 'color', 'code' => 'forbidden_unless_custom']]];
        yield 'parent with color' => [['colorMode' => 'PARENT', 'color' => '#123456'], true, [['field' => 'color', 'code' => 'forbidden_unless_custom']]];
        yield 'invalid color' => [['colorMode' => 'CUSTOM', 'color' => 'red'], true, [['field' => 'color', 'code' => 'invalid_color']]];
    }

    /**
     * @param array<string, mixed>                     $extra
     * @param list<array{field: string, code: string}> $errors
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('colorModeErrors')]
    public function testEveryColorFieldErrorIsReportedWithoutWriting(array $extra, bool $withParent, array $errors): void
    {
        $parent = $this->createGroup('Parent');
        $count = $this->ownGroupCount();
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'Child', 'parentId' => $withParent ? $parent['id'] : null, 'sortOrder' => 0, ...$extra]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($errors, $this->decode()['errors']);
        self::assertSame($count, $this->ownGroupCount());

        $this->requestRaw('PUT', '/api/v1/account-groups/'.self::stringValue($parent, 'id'), ['label' => 'Parent', 'parentId' => $withParent ? $parent['id'] : null, 'sortOrder' => 0, 'version' => 1, ...$extra]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($errors, $this->decode()['errors']);
    }

    public function testAModeChangeWithAStaleVersionIsAConflictAndAForeignParentIsRefused(): void
    {
        $group = $this->createGroup('Group');
        $this->requestRaw('PUT', '/api/v1/account-groups/'.self::stringValue($group, 'id'), [
            'label' => 'Group', 'parentId' => null, 'sortOrder' => 0, 'version' => 7, 'colorMode' => 'AUTO',
        ]);
        self::assertResponseStatusCodeSame(409);

        $foreign = '00000000-0000-7000-8000-0000000f0001';
        $this->insertGroup($foreign, WorkspaceFixture::OTHER_WORKSPACE, 'Foreign');
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'Child', 'parentId' => $foreign, 'sortOrder' => 0, 'colorMode' => 'PARENT']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMovePersistsTheOrderKeepsTheVersionAndIsAudited(): void
    {
        [$parent, $a, $b, $c] = $this->parentWithChildren(['A', 'B', 'C']);

        $this->requestMove($c, $a, [$a, $b, $c]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['parentId' => $parent, 'siblingIds' => [$c, $a, $b], 'rank' => 1], $this->decode());
        self::assertSame([$c, $a, $b], $this->childIds($parent));
        self::assertSame([1, 1, 1], array_map(fn (string $id): int => $this->groupVersion($id), [$a, $b, $c]));
        self::assertSame(1, $this->movedEventCount());

        $this->requestMove($c, null, [$c, $a, $b]);
        self::assertSame([$a, $b, $c], $this->childIds($parent));
        self::assertSame(3, $this->decode()['rank']);
        self::assertSame([0, 1, 2], $this->sortOrders([$a, $b, $c]));
    }

    public function testAHiddenArchivedSiblingKeepsItsPlaceWhenAVisibleOneMoves(): void
    {
        [$parent, $a, $b, $c] = $this->parentWithChildren(['A', 'B', 'C']);
        $this->connection->update('account_groups', ['archived_at' => '2026-09-01 12:00:00+00'], ['id' => $b]);

        $this->requestMove($c, $a, [$a, $c]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$c, $a], $this->decode()['siblingIds']);
        // Full order was A B C: C moves before A, B stays after A.
        self::assertSame([0, 1, 2], $this->sortOrders([$c, $a, $b]));
    }

    public function testAStaleSiblingListIsAConflictAndTheSavedOrderStaysIntact(): void
    {
        [$parent, $a, $b, $c] = $this->parentWithChildren(['A', 'B', 'C']);
        $this->requestMove($c, $a, [$a, $b, $c]);
        self::assertResponseStatusCodeSame(200);

        $this->requestMove($b, $a, [$a, $b, $c]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-account-group-order', $this->decode()['type']);
        self::assertSame([$c, $a, $b], $this->childIds($parent));
    }

    public function testMalformedAndOversizedSiblingListsAreRefusedBeforeAnyWrite(): void
    {
        [$parent, $a, $b] = $this->parentWithChildren(['A', 'B']);
        $many = array_map(static fn (int $n): string => sprintf('00000000-0000-7000-8000-%012d', $n), range(1, 101));
        $exactlyMax = array_slice($many, 0, 100);

        foreach ([[], [$a, $a], ['not-a-uuid'], $many, ['x' => $a], null] as $expected) {
            $this->requestRaw('POST', '/api/v1/account-groups/'.$b.'/move', ['beforeGroupId' => $a, 'expectedSiblingIds' => $expected]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->requestRaw('POST', '/api/v1/account-groups/'.$b.'/move', ['beforeGroupId' => 'nope', 'expectedSiblingIds' => [$a, $b]]);
        self::assertResponseStatusCodeSame(422);
        $this->requestRaw('POST', '/api/v1/account-groups/'.$b.'/move', ['expectedSiblingIds' => [$a, $b]]);
        self::assertResponseStatusCodeSame(422);

        // 100 unique ids pass validation and fail on the saved order, not on size.
        $this->requestMove($b, null, $exactlyMax);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$a, $b], $this->childIds($parent));
    }

    public function testForeignGroupsAnswerLikeUnknownOnesAndForeignAnchorsAreRefused(): void
    {
        [$parent, $a, $b] = $this->parentWithChildren(['A', 'B']);
        $foreign = '00000000-0000-7000-8000-0000000f0002';
        $this->insertGroup($foreign, WorkspaceFixture::OTHER_WORKSPACE, 'Foreign');

        $this->requestMove($foreign, null, [$foreign]);
        $foreignStatus = $this->client->getResponse()->getStatusCode();
        $this->requestMove('00000000-0000-7000-8000-0000000f0003', null, ['00000000-0000-7000-8000-0000000f0003']);
        self::assertSame(404, $foreignStatus);
        self::assertResponseStatusCodeSame(404);

        $this->requestMove($b, $foreign, [$a, $b]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid-account-group-anchor', $this->decode()['type']);
        self::assertSame([$a, $b], $this->childIds($parent));
        self::assertSame(0, $this->sortOrders([$foreign])[0]);
    }

    public function testAnArchivedGroupAndAnArchivedOrNonSiblingAnchorAreRefused(): void
    {
        [$parent, $a, $b, $c] = $this->parentWithChildren(['A', 'B', 'C']);
        $this->connection->update('account_groups', ['archived_at' => '2026-09-01 12:00:00+00'], ['id' => $c]);

        $this->requestMove($c, null, [$a, $b]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/account-group-archived', $this->decode()['type']);

        $this->requestMove($a, $c, [$a, $b]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid-account-group-anchor', $this->decode()['type']);

        $this->requestMove($a, $parent, [$a, $b]);
        self::assertResponseStatusCodeSame(422);
        $this->requestMove($a, $a, [$a, $b]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testANewGroupIsAppendedAndIgnoresADeprecatedSortOrder(): void
    {
        [$parent, $a] = $this->parentWithChildren(['A']);

        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'B', 'parentId' => $parent, 'sortOrder' => 0]);
        self::assertResponseStatusCodeSame(201);
        $b = self::stringValue($this->decode(), 'id');
        $this->requestRaw('POST', '/api/v1/account-groups', ['label' => 'C', 'parentId' => $parent]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame([0, 1, 2], $this->sortOrders([$a, $b, self::stringValue($this->decode(), 'id')]));
    }

    public function testReparentingThroughTheModalAppendsAfterTheNewParentsLastSibling(): void
    {
        [$parent, $a, $b] = $this->parentWithChildren(['A', 'B']);
        $other = $this->createGroup('Other');
        $this->requestRaw('PUT', '/api/v1/account-groups/'.$b, ['label' => 'B', 'parentId' => $other['id'], 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->requestRaw('PUT', '/api/v1/account-groups/'.$a, ['label' => 'A', 'parentId' => $other['id'], 'version' => 1]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame([$b, $a], $this->childIds(self::stringValue($other, 'id')));
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string> the parent id, then each child id in creation order
     */
    private function parentWithChildren(array $labels): array
    {
        $parent = self::stringValue($this->createGroup('Parent'), 'id');
        $ids = [$parent];
        foreach ($labels as $label) {
            $ids[] = self::stringValue($this->createGroup($label, $parent), 'id');
        }

        return $ids;
    }

    /** @param list<string> $expected */
    private function requestMove(string $id, ?string $before, array $expected): void
    {
        $this->requestRaw('POST', '/api/v1/account-groups/'.$id.'/move', ['beforeGroupId' => $before, 'expectedSiblingIds' => $expected]);
    }

    /** @return list<string> */
    private function childIds(string $parentId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM account_groups WHERE parent_id = :parent AND archived_at IS NULL ORDER BY sort_order, normalized_label, id',
            ['parent' => $parentId],
        );

        return $ids;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function sortOrders(array $ids): array
    {
        return array_map(fn (string $id): int => (int) self::scalar($this->connection->fetchOne('SELECT sort_order FROM account_groups WHERE id = :id', ['id' => $id])), $ids);
    }

    private function groupVersion(string $id): int
    {
        return (int) self::scalar($this->connection->fetchOne('SELECT version FROM account_groups WHERE id = :id', ['id' => $id]));
    }

    private static function scalar(mixed $value): int|string
    {
        self::assertTrue(is_int($value) || is_string($value));

        return $value;
    }

    private function movedEventCount(): int
    {
        return (int) self::scalar($this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type = 'account_group.moved'"));
    }

    /** @param array<string, mixed> $body */
    private function requestRaw(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
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
    private function createGroup(string $label, ?string $parentId = null): array
    {
        $this->requestCreate($label, $parentId);
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    private function requestCreate(string $label, ?string $parentId = null): void
    {
        $this->client->request(
            'POST',
            '/api/v1/account-groups',
            server: self::jsonHeaders(),
            content: json_encode($this->payload($label, $parentId), JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $group */
    private function requestUpdate(string $id, array $group): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/account-groups/'.$id,
            server: self::jsonHeaders(),
            content: json_encode([
                'label' => $group['label'],
                'parentId' => $group['parentId'] ?? null,
                'sortOrder' => $group['sortOrder'],
                'version' => $group['version'],
                'color' => $group['color'] ?? null,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function requestArchive(string $id, int $version): void
    {
        $this->client->request(
            'POST',
            '/api/v1/account-groups/'.$id.'/archive',
            server: self::jsonHeaders(),
            content: json_encode(['version' => $version], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function payload(string $label, ?string $parentId = null): array
    {
        return [
            'label' => $label,
            'parentId' => $parentId,
            'sortOrder' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(string $label): array
    {
        return [
            'label' => $label,
            'parentId' => null,
            'sortOrder' => 0,
            'version' => 1,
            'color' => '#000000',
        ];
    }

    private function ownGroupCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM account_groups WHERE workspace_id = ?',
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function insertGroup(string $id, string $workspaceId, string $label): void
    {
        $this->connection->insert('account_groups', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'label' => $label,
            'parent_id' => null,
            'sort_order' => 0,
            'depth' => 1,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
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

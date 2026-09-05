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
        $this->requestUpdate($id, [...$group, 'label' => 'Liquidités']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);

        $this->requestUpdate($id, [...$group, 'label' => 'Ancien onglet']);
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
        $this->client->request('GET', '/api/v1/account-groups?perPage=0');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/v1/account-groups?perPage=101');
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

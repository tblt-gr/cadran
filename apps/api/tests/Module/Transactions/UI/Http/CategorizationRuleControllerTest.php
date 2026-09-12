<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CategorizationRuleControllerTest extends WebTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string OWN_ARCHIVED_ACCOUNT = '00000000-0000-7000-8000-0000000000d4';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string OWN_EXPENSE = '00000000-0000-7000-8000-0000000000c1';
    private const string OWN_INCOME = '00000000-0000-7000-8000-0000000000c2';
    private const string OWN_OTHER_EXPENSE = '00000000-0000-7000-8000-0000000000c4';
    private const string OWN_ARCHIVED_EXPENSE = '00000000-0000-7000-8000-0000000000c5';
    private const string OTHER_CATEGORY = '00000000-0000-7000-8000-0000000000c3';
    private const string UNKNOWN_ID = '00000000-0000-7000-8000-0000000000f8';
    private const string TX_CARREFOUR = '00000000-0000-7000-8000-000000000101';
    private const string TX_MANUAL = '00000000-0000-7000-8000-000000000102';
    private const string TX_AUCHAN = '00000000-0000-7000-8000-000000000103';
    private const string TX_VOIDED = '00000000-0000-7000-8000-000000000104';
    private const string TX_LATER = '00000000-0000-7000-8000-000000000105';
    private const string TX_INCOME = '00000000-0000-7000-8000-000000000106';
    private const string TX_IMPORTED = '00000000-0000-7000-8000-000000000107';
    private const string TX_FOREIGN = '00000000-0000-7000-8000-000000000109';
    private const array RULE_KEYS = [
        'id', 'label', 'priority', 'accountScope', 'conditions', 'targetCategoryId', 'targetCategoryLabel',
        'targetAxes', 'targetCounterparty', 'effectiveFrom', 'effectiveTo', 'active', 'deactivatedReason',
        'appliedCount', 'version', 'createdAt', 'updatedAt', 'archivedAt',
    ];

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
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
        $this->seedAccount(self::OWN_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');
        $this->seedAccount(self::OWN_SECOND_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Compte joint');
        $this->seedAccount(self::OWN_ARCHIVED_ACCOUNT, WorkspaceFixture::OWN_WORKSPACE, 'Ancien compte', archived: true);
        $this->seedAccount(self::OTHER_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');
        $this->seedCategory(self::OWN_EXPENSE, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Courses');
        $this->seedCategory(self::OWN_INCOME, WorkspaceFixture::OWN_WORKSPACE, 'INCOME', 'Salaire');
        $this->seedCategory(self::OWN_OTHER_EXPENSE, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Restaurants');
        $this->seedCategory(self::OWN_ARCHIVED_EXPENSE, WorkspaceFixture::OWN_WORKSPACE, 'EXPENSE', 'Ancienne', archived: true);
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

    public function testTheOwnerCreatesListsEditsDeactivatesAndArchivesARule(): void
    {
        $second = $this->createRule(['label' => 'Restaurants', 'priority' => 20, 'targetCategoryId' => self::OWN_OTHER_EXPENSE]);
        $first = $this->createRule();
        self::assertSame(self::RULE_KEYS, array_keys($first));
        self::assertSame('Courses Carrefour', $first['label']);
        self::assertSame('Courses', $first['targetCategoryLabel']);
        self::assertSame(['ESSENTIAL'], $first['targetAxes']);
        self::assertSame([
            'rawLabel' => ['operator' => 'CONTAINS', 'value' => 'CARREFOUR'],
            'normalizedLabel' => null, 'counterparty' => null, 'mcc' => null,
            'amount' => ['min' => '-250.00', 'max' => '-5.00', 'assetCode' => 'EUR'],
            'direction' => 'OUT',
        ], $first['conditions']);
        self::assertTrue($first['active']);
        self::assertNull($first['deactivatedReason']);
        self::assertSame(0, $first['appliedCount']);
        self::assertSame(1, $first['version']);

        $this->client->request('GET', '/api/v1/categorization-rules');
        self::assertResponseIsSuccessful();
        $page = $this->decode();
        self::assertSame([$first['id'], $second['id']], array_column($this->items($page), 'id'));
        self::assertSame(2, $page['total']);

        $id = self::text($first, 'id');
        $this->requestUpdate($id, [...$this->rulePayload(['priority' => 30]), 'active' => true, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode()['version']);

        $this->requestUpdate($id, [...$this->rulePayload(), 'active' => true, 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/stale-version', $this->decode()['type']);

        $this->requestUpdate($id, [...$this->rulePayload(), 'active' => false, 'version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame('USER', $this->decode()['deactivatedReason']);

        $this->requestUpdate($id, [...$this->rulePayload(), 'active' => true, 'version' => 3]);
        self::assertResponseIsSuccessful();
        $reactivated = $this->decode();
        self::assertTrue($reactivated['active']);
        self::assertNull($reactivated['deactivatedReason']);

        $this->requestArchive($id, 4);
        self::assertResponseIsSuccessful();
        $archived = $this->decode();
        self::assertFalse($archived['active']);
        self::assertNotNull($archived['archivedAt']);

        $this->requestUpdate($id, [...$this->rulePayload(), 'active' => true, 'version' => 5]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/rules.archived', $this->decode()['type']);

        $this->client->request('GET', '/api/v1/categorization-rules');
        self::assertSame([$second['id']], array_column($this->items($this->decode()), 'id'));
        $this->client->request('GET', '/api/v1/categorization-rules?includeArchived=true');
        self::assertCount(2, $this->items($this->decode()));

        $events = $this->connection->fetchAllAssociative(
            'SELECT event_type, after_json FROM audit_events WHERE workspace_id = ? AND entity_id = ? ORDER BY occurred_at, id',
            [WorkspaceFixture::OWN_WORKSPACE, $id],
        );
        self::assertSame(
            ['categorization_rule.created', 'categorization_rule.updated', 'categorization_rule.updated', 'categorization_rule.updated', 'categorization_rule.archived'],
            array_column($events, 'event_type'),
        );
        foreach ($events as $event) {
            self::assertStringNotContainsString('CARREFOUR', self::text($event, 'after_json'));
            self::assertStringNotContainsString('250', self::text($event, 'after_json'));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafePatterns(): iterable
    {
        yield 'too long' => [str_repeat('a', 400), 'too_long'];
        yield 'lookaround' => ['^(?!TEST)', 'lookaround'];
        yield 'backreference' => ['(a)\1+', 'backreference'];
        yield 'nested quantifier' => ['(a+)+$', 'nested_quantifier'];
        yield 'atomic group' => ['(?>a+)b', 'possessive'];
        yield 'inline modifier' => ['a(?i)b', 'inline_modifier'];
        yield 'unbalanced group' => ['(ab', 'invalid'];
    }

    #[DataProvider('unsafePatterns')]
    public function testAnUnsafePatternIsRefusedWithAnExplanation(string $pattern, string $reason): void
    {
        $this->requestCreate($this->rulePayload(['conditions' => ['counterparty' => ['operator' => 'REGEX', 'value' => $pattern]]]));

        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode();
        self::assertSame('/problems/rules.unsafe_pattern', $problem['type']);
        self::assertSame($reason, $problem['reason']);
        self::assertSame('counterparty', $problem['field']);
        self::assertNotSame('', $problem['detail']);
        self::assertSame(0, $this->ruleCount());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidReferences(): iterable
    {
        yield 'foreign category' => [['targetCategoryId' => self::OTHER_CATEGORY]];
        yield 'unknown category' => [['targetCategoryId' => self::UNKNOWN_ID]];
        yield 'archived category' => [['targetCategoryId' => self::OWN_ARCHIVED_EXPENSE]];
        yield 'foreign scope account' => [['accountScope' => [self::OTHER_ACCOUNT]]];
        yield 'unknown scope account' => [['accountScope' => [self::UNKNOWN_ID]]];
        yield 'archived scope account' => [['accountScope' => [self::OWN_ARCHIVED_ACCOUNT]]];
        yield 'duplicated scope account' => [['accountScope' => [self::OWN_ACCOUNT, self::OWN_ACCOUNT]]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidReferences')]
    public function testAReferenceOutsideTheCallerWorkspaceIsRefusedLikeAnUnknownOne(array $overrides): void
    {
        $this->requestCreate($this->rulePayload(['targetCategoryId' => self::UNKNOWN_ID]));
        self::assertResponseStatusCodeSame(422);
        $unknown = $this->decode();

        $this->requestCreate($this->rulePayload($overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame($unknown, $this->decode());
        self::assertSame('/problems/rules.invalid_reference', $unknown['type']);
        self::assertSame(0, $this->ruleCount());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedRules(): iterable
    {
        yield 'priority zero' => [['priority' => 0]];
        yield 'priority above 999' => [['priority' => 1000]];
        yield 'blank label' => [['label' => '']];
        yield 'no condition' => [['conditions' => []]];
        yield 'text value beyond 120 characters' => [['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => str_repeat('a', 121)]]]];
        yield 'native number amount' => [['conditions' => ['amount' => ['min' => -5, 'max' => null, 'assetCode' => 'EUR']]]];
        yield 'period ending before it starts' => [['effectiveTo' => '2025-12-31']];
        yield 'malformed date' => [['effectiveFrom' => '01/01/2026']];
        yield 'scope beyond twenty accounts' => [['accountScope' => array_map(static fn (int $i): string => sprintf('00000000-0000-7000-8000-%012d', $i), range(1, 21))]];
        yield 'unknown field' => [['colour' => 'red']];
        yield 'unknown axis' => [['targetAxes' => ['LUXURY']]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('malformedRules')]
    public function testAMalformedRuleIsRefused(array $overrides): void
    {
        $this->requestCreate($this->rulePayload($overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->ruleCount());
    }

    public function testAnotherWorkspaceCannotSeeEditArchivePreviewOrApplyARule(): void
    {
        $this->seedHistory();
        $rule = $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'CARREFOUR']]]);
        $id = self::text($rule, 'id');
        $token = $this->preview(null);

        $this->signIn(WorkspaceFixture::OTHER_OWNER_EMAIL);
        $this->client->request('GET', '/api/v1/categorization-rules?includeArchived=true');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items($this->decode()));

        $this->requestUpdate($id, [...$this->rulePayload(), 'active' => true, 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
        $this->requestArchive($id, 1);
        self::assertResponseStatusCodeSame(404);
        $this->requestPreview($id, '2026-01-01', '2026-03-31');
        self::assertResponseStatusCodeSame(404);
        $this->requestApply($token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/rules.preview_stale', $this->decode()['type']);

        $this->signIn();
        self::assertSame([], $this->splitRows(self::TX_CARREFOUR));
        self::assertSame(1, $this->ruleVersion($id));
    }

    public function testPreviewCountsWithoutWritingAndApplyWritesTraceableRuleSplitsOnce(): void
    {
        $this->seedHistory();
        $rule = $this->createRule([
            'conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']],
            'targetCounterparty' => 'Carrefour',
        ]);
        $ruleId = self::text($rule, 'id');

        $this->requestPreview(null, '2026-01-01', '2026-03-31');
        self::assertResponseIsSuccessful();
        $preview = $this->decode();
        self::assertSame(
            ['previewToken', 'matched', 'wouldChange', 'skippedManual', 'conflicts', 'samples', 'skipped', 'deactivatedRuleIds'],
            array_keys($preview),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', self::text($preview, 'previewToken'));
        // Carrefour (uncategorised), manual Carrefour and imported Carrefour match; the voided,
        // later, income (target type mismatch), Auchan and foreign movements do not.
        self::assertSame(3, $preview['matched']);
        self::assertSame(2, $preview['wouldChange']);
        self::assertSame(1, $preview['skippedManual']);
        self::assertSame([], $preview['conflicts']);
        self::assertSame([['transactionId' => self::TX_MANUAL, 'bookedOn' => '2026-02-05', 'reason' => 'MANUAL_CATEGORIZATION']], $preview['skipped']);
        self::assertSame([
            ['transactionId' => self::TX_CARREFOUR, 'bookedOn' => '2026-02-03', 'currentCategoryId' => null, 'targetCategoryId' => self::OWN_EXPENSE],
            ['transactionId' => self::TX_IMPORTED, 'bookedOn' => '2026-02-07', 'currentCategoryId' => null, 'targetCategoryId' => self::OWN_EXPENSE],
        ], $preview['samples']);
        self::assertSame([], $this->splitRows(self::TX_CARREFOUR), 'A preview writes no categorisation.');
        self::assertSame(1, $this->transactionVersion(self::TX_CARREFOUR));

        $this->requestApply(self::text($preview, 'previewToken'));
        self::assertResponseIsSuccessful();
        self::assertSame(['changed' => 2, 'skippedManual' => 1, 'deactivatedRuleIds' => []], $this->decode());

        self::assertSame([['category_id' => self::OWN_EXPENSE, 'categorization_origin' => 'RULE', 'categorization_rule_id' => $ruleId, 'analytic_axes' => '["ESSENTIAL"]']], $this->splitRows(self::TX_CARREFOUR));
        self::assertSame(2, $this->transactionVersion(self::TX_CARREFOUR));
        self::assertSame('Carrefour', $this->counterparty(self::TX_CARREFOUR), 'An empty counterparty is filled.');
        self::assertSame('CARREFOUR MARKET PARIS', $this->counterparty(self::TX_IMPORTED), 'An imported counterparty is never overwritten.');
        self::assertSame([['category_id' => self::OWN_OTHER_EXPENSE, 'categorization_origin' => 'MANUAL', 'categorization_rule_id' => null, 'analytic_axes' => '[]']], $this->splitRows(self::TX_MANUAL));
        self::assertSame([], $this->splitRows(self::TX_FOREIGN, WorkspaceFixture::OTHER_WORKSPACE));
        self::assertSame(2, $this->appliedCount($ruleId));

        $this->client->request('GET', '/api/v1/transactions/'.self::TX_CARREFOUR);
        $splits = $this->decode()['splits'];
        self::assertIsList($splits);
        self::assertIsArray($splits[0]);
        self::assertSame('RULE', $splits[0]['categorizationOrigin']);
        self::assertSame($ruleId, $splits[0]['categorizationRuleId']);

        $this->requestApply(self::text($preview, 'previewToken'));
        self::assertResponseStatusCodeSame(409, 'A consumed preview token cannot be replayed.');
        self::assertSame('/problems/rules.preview_stale', $this->decode()['type']);

        $this->requestPreview(null, '2026-01-01', '2026-03-31');
        $again = $this->decode();
        self::assertSame(3, $again['matched']);
        self::assertSame(0, $again['wouldChange'], 'An unchanged result is not counted again.');

        $audit = $this->connection->fetchAllAssociative(
            "SELECT after_json FROM audit_events WHERE workspace_id = ? AND event_type = 'categorization_rule.applied'",
            [WorkspaceFixture::OWN_WORKSPACE],
        );
        self::assertCount(1, $audit);
        self::assertStringNotContainsString('CARREFOUR', self::text($audit[0], 'after_json'));
    }

    public function testTheLowestPriorityWinsAndTheLosingRuleIsReportedAsAConflict(): void
    {
        $this->seedHistory();
        $losing = $this->createRule(['priority' => 20, 'targetCategoryId' => self::OWN_OTHER_EXPENSE, 'conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']]]);
        $winning = $this->createRule(['priority' => 10, 'conditions' => ['rawLabel' => ['operator' => 'REGEX', 'value' => '^cb carrefour']]]);

        $this->requestPreview(null, '2026-01-01', '2026-03-31');
        $preview = $this->decode();
        self::assertSame([['transactionId' => self::TX_CARREFOUR, 'ruleIds' => [$winning['id'], $losing['id']]]], $preview['conflicts']);
        self::assertSame(self::OWN_EXPENSE, $this->samples($preview)[0]['targetCategoryId']);

        $this->requestPreview(self::text($losing, 'id'), '2026-01-01', '2026-03-31');
        $single = $this->decode();
        self::assertSame(3, $single['matched']);
        self::assertSame(0, $single['wouldChange'], 'A lower-priority rule never displaces the winning rule.');
    }

    public function testAManualEditAfterThePreviewMakesTheApplyStale(): void
    {
        $this->seedHistory();
        $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']]]);
        $token = $this->preview(null);

        $this->client->request('PUT', '/api/v1/transactions/'.self::TX_CARREFOUR.'/splits', server: self::jsonHeaders(), content: json_encode([
            'version' => 1,
            'splits' => [['categoryId' => self::OWN_OTHER_EXPENSE, 'amount' => ['value' => '-42.90', 'assetCode' => 'EUR'], 'analyticAxes' => [], 'note' => null]],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->requestApply($token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/rules.preview_stale', $this->decode()['type']);
        self::assertSame([['category_id' => self::OWN_OTHER_EXPENSE, 'categorization_origin' => 'MANUAL', 'categorization_rule_id' => null, 'analytic_axes' => '[]']], $this->splitRows(self::TX_CARREFOUR));
        self::assertSame([], $this->splitRows(self::TX_IMPORTED), 'A stale run writes nothing.');
    }

    public function testARuleEditOrAnExpiryAfterThePreviewMakesTheApplyStale(): void
    {
        $this->seedHistory();
        $rule = $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']]]);
        $token = $this->preview(null);
        $this->requestUpdate(self::text($rule, 'id'), [...$this->rulePayload(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']], 'priority' => 5]), 'active' => true, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $this->requestApply($token);
        self::assertResponseStatusCodeSame(409);

        $fresh = $this->preview(null);
        $this->connection->executeStatement("UPDATE transaction_categorization_previews SET expires_at = now() - interval '1 second', created_at = now() - interval '16 minutes' WHERE workspace_id = ?", [WorkspaceFixture::OWN_WORKSPACE]);
        $this->requestApply($fresh);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->splitRows(self::TX_CARREFOUR));
    }

    public function testAPreviewBeyondFiveThousandMovementsIsRefused(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO transaction_transactions (id, workspace_id, account_id, asset_code, amount_value, amount_scale, state, nature, source, booked_on, raw_label, version, created_at, updated_at)
             SELECT gen_random_uuid(), :workspace, :account, 'EUR', -1.00, 2, 'BOOKED', 'EXPENSE', 'MANUAL', DATE '2026-02-01', 'BULK ' || g, 1, now(), now()
             FROM generate_series(1, 5001) AS g",
            ['workspace' => WorkspaceFixture::OWN_WORKSPACE, 'account' => self::OWN_ACCOUNT],
        );
        $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'bulk']]]);

        $this->requestPreview(null, '2026-01-01', '2026-03-31');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/rules.range_too_large', $this->decode()['type']);
        self::assertSame(0, (int) self::text(['n' => $this->connection->fetchOne('SELECT count(*) FROM transaction_categorization_previews')], 'n'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedPreviews(): iterable
    {
        yield 'reversed period' => [['ruleId' => null, 'from' => '2026-03-31', 'to' => '2026-01-01']];
        yield 'malformed date' => [['ruleId' => null, 'from' => '2026-1-1', 'to' => '2026-03-31']];
        yield 'unknown field' => [['ruleId' => null, 'from' => '2026-01-01', 'to' => '2026-03-31', 'force' => true]];
        yield 'malformed rule' => [['ruleId' => 'rule', 'from' => '2026-01-01', 'to' => '2026-03-31']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('malformedPreviews')]
    public function testAMalformedPreviewIsRefused(array $body): void
    {
        $this->client->request('POST', '/api/v1/categorization-rules/preview', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreationAppliesTheWinningRuleOnlyWithoutAnExplicitCategoryInPeriodAndScope(): void
    {
        $rule = $this->createRule([
            'conditions' => ['counterparty' => ['operator' => 'EQUALS', 'value' => 'carrefour']],
            'accountScope' => [self::OWN_ACCOUNT],
            'targetCounterparty' => 'Ignored because present',
        ]);
        $ruleId = self::text($rule, 'id');

        $automatic = $this->createTransaction([]);
        $split = $this->firstSplit($automatic);
        self::assertSame(self::OWN_EXPENSE, $split['categoryId']);
        self::assertSame('RULE', $split['categorizationOrigin']);
        self::assertSame($ruleId, $split['categorizationRuleId']);
        self::assertSame(['ESSENTIAL'], $split['analyticAxes']);
        self::assertSame('Carrefour', $automatic['counterparty']);
        self::assertSame(1, $this->appliedCount($ruleId));

        $manual = $this->firstSplit($this->createTransaction(['categoryId' => self::OWN_OTHER_EXPENSE]));
        self::assertSame('MANUAL', $manual['categorizationOrigin']);
        self::assertNull($manual['categorizationRuleId']);

        self::assertSame([], $this->createTransaction(['splits' => []])['splits'], 'An explicit empty allocation stays uncategorised.');
        self::assertSame([], $this->createTransaction(['bookedOn' => '2025-12-31'])['splits'], 'Outside every period stays uncategorised.');
        self::assertSame([], $this->createTransaction(['accountId' => self::OWN_SECOND_ACCOUNT])['splits'], 'Outside the scope stays uncategorised.');
        self::assertSame(1, $this->appliedCount($ruleId));
    }

    public function testCreationFillsOnlyAnEmptyCounterparty(): void
    {
        $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']], 'targetCounterparty' => 'Carrefour']);

        self::assertSame('Carrefour', $this->createTransaction(['counterparty' => null])['counterparty']);
        self::assertSame('Carrefour Market', $this->createTransaction(['counterparty' => 'Carrefour Market'])['counterparty']);
    }

    public function testAnEditKeepsRuleProvenanceUntilTheCategorisationChanges(): void
    {
        $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']]]);
        $created = $this->createTransaction([]);
        $id = self::text($created, 'id');

        $this->requestTransactionUpdate($id, [...$this->transactionPayload(['categoryId' => self::OWN_EXPENSE, 'note' => 'Corrigé']), 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame('RULE', $this->firstSplit($this->decode())['categorizationOrigin']);

        $this->requestTransactionUpdate($id, [...$this->transactionPayload(['categoryId' => self::OWN_OTHER_EXPENSE]), 'version' => 2]);
        self::assertResponseIsSuccessful();
        $changed = $this->firstSplit($this->decode());
        self::assertSame('MANUAL', $changed['categorizationOrigin']);
        self::assertNull($changed['categorizationRuleId']);
    }

    public function testArchivingACategoryDeactivatesEveryActiveRuleTargetingIt(): void
    {
        $active = $this->createRule(['label' => 'Active']);
        $userInactive = $this->createRule(['label' => 'Inactive']);
        $this->requestUpdate(self::text($userInactive, 'id'), [...$this->rulePayload(['label' => 'Inactive']), 'active' => false, 'version' => 1]);
        self::assertResponseIsSuccessful();
        $other = $this->createRule(['label' => 'Other', 'targetCategoryId' => self::OWN_OTHER_EXPENSE]);

        $this->client->request('POST', '/api/v1/categories/'.self::OWN_EXPENSE.'/archive', server: self::jsonHeaders(), content: json_encode(['version' => 1], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        self::assertSame([false, 'CATEGORY_ARCHIVED'], $this->ruleState(self::text($active, 'id')));
        self::assertSame([false, 'USER'], $this->ruleState(self::text($userInactive, 'id')));
        self::assertSame([true, null], $this->ruleState(self::text($other, 'id')));
        self::assertSame(1, (int) self::text(['n' => $this->connection->fetchOne(
            "SELECT count(*) FROM audit_events WHERE workspace_id = ? AND event_type = 'categorization_rule.deactivated' AND entity_id = ?",
            [WorkspaceFixture::OWN_WORKSPACE, $active['id']],
        )], 'n'));

        $this->requestUpdate(self::text($active, 'id'), [...$this->rulePayload(['label' => 'Active']), 'active' => true, 'version' => 2]);
        self::assertResponseStatusCodeSame(422, 'An archived target can never be reactivated.');
        self::assertSame('/problems/rules.invalid_reference', $this->decode()['type']);
    }

    public function testARuleExceedingItsBacktrackBudgetIsDeactivatedAndReported(): void
    {
        $this->seedTransaction(self::TX_CARREFOUR, '2026-02-03', '-42.90', str_repeat('a', 40).'!');
        $rule = $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'REGEX', 'value' => '^(a|a)*$']]]);
        $ruleId = self::text($rule, 'id');

        $this->requestPreview(null, '2026-01-01', '2026-03-31');
        self::assertResponseIsSuccessful();
        $preview = $this->decode();
        self::assertSame([$ruleId], $preview['deactivatedRuleIds']);
        self::assertSame(0, $preview['matched']);
        self::assertSame([false, 'PATTERN_BUDGET_EXCEEDED'], $this->ruleState($ruleId));
        self::assertSame([], $this->splitRows(self::TX_CARREFOUR));
        self::assertSame(1, (int) self::text(['n' => $this->connection->fetchOne(
            "SELECT count(*) FROM audit_events WHERE workspace_id = ? AND event_type = 'categorization_rule.deactivated' AND entity_id = ?",
            [WorkspaceFixture::OWN_WORKSPACE, $ruleId],
        )], 'n'));

        $second = $this->createRule(['conditions' => ['rawLabel' => ['operator' => 'REGEX', 'value' => '^(a|a)*$']]]);
        self::assertSame([], $this->createTransaction(['rawLabel' => str_repeat('a', 40).'!'])['splits']);
        self::assertSame([false, 'PATTERN_BUDGET_EXCEEDED'], $this->ruleState(self::text($second, 'id')));
    }

    private function seedHistory(): void
    {
        $this->seedTransaction(self::TX_CARREFOUR, '2026-02-03', '-42.90', 'CB CARREFOUR 1234');
        $this->seedTransaction(self::TX_MANUAL, '2026-02-05', '-12.00', 'CB CARREFOUR 5678');
        $this->seedSplit(self::TX_MANUAL, self::OWN_OTHER_EXPENSE, '-12.00');
        $this->seedTransaction(self::TX_AUCHAN, '2026-02-06', '-8.00', 'CB AUCHAN');
        $this->seedTransaction(self::TX_IMPORTED, '2026-02-07', '-99.99', 'CB CARREFOUR MARKET', counterparty: 'CARREFOUR MARKET PARIS', source: 'IMPORT');
        $this->seedTransaction(self::TX_VOIDED, '2026-02-08', '-5.00', 'CB CARREFOUR VOID', state: 'VOIDED');
        $this->seedTransaction(self::TX_LATER, '2026-05-01', '-5.00', 'CB CARREFOUR LATER');
        $this->seedTransaction(self::TX_INCOME, '2026-02-09', '10.00', 'VIR CARREFOUR', nature: 'INCOME');
        $this->seedTransaction(self::TX_FOREIGN, '2026-02-03', '-42.90', 'CB CARREFOUR 1234', workspace: WorkspaceFixture::OTHER_WORKSPACE, account: self::OTHER_ACCOUNT);
    }

    private function preview(?string $ruleId): string
    {
        $this->requestPreview($ruleId, '2026-01-01', '2026-03-31');
        self::assertResponseIsSuccessful();

        return self::text($this->decode(), 'previewToken');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function rulePayload(array $overrides = []): array
    {
        return [
            'label' => 'Courses Carrefour',
            'priority' => 10,
            'accountScope' => [],
            'conditions' => [
                'rawLabel' => ['operator' => 'CONTAINS', 'value' => 'CARREFOUR'],
                'amount' => ['min' => '-250.00', 'max' => '-5.00', 'assetCode' => 'EUR'],
                'direction' => 'OUT',
            ],
            'targetCategoryId' => self::OWN_EXPENSE,
            'targetAxes' => ['ESSENTIAL'],
            'targetCounterparty' => null,
            'effectiveFrom' => '2026-01-01',
            'effectiveTo' => null,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createRule(array $overrides = []): array
    {
        $this->requestCreate($this->rulePayload($overrides));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestCreate(array $body): void
    {
        $this->client->request('POST', '/api/v1/categorization-rules', server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function requestUpdate(string $id, array $body): void
    {
        $this->client->request('PUT', '/api/v1/categorization-rules/'.$id, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function requestArchive(string $id, int $version): void
    {
        $this->client->request('POST', '/api/v1/categorization-rules/'.$id.'/archive', server: self::jsonHeaders(), content: json_encode(['version' => $version], JSON_THROW_ON_ERROR));
    }

    private function requestPreview(?string $ruleId, string $from, string $to): void
    {
        $this->client->request('POST', '/api/v1/categorization-rules/preview', server: self::jsonHeaders(), content: json_encode(['ruleId' => $ruleId, 'from' => $from, 'to' => $to], JSON_THROW_ON_ERROR));
    }

    private function requestApply(string $token): void
    {
        $this->client->request('POST', '/api/v1/categorization-rules/apply', server: self::jsonHeaders(), content: json_encode(['previewToken' => $token], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function transactionPayload(array $overrides = []): array
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
            'categoryId' => null,
            'splits' => null,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createTransaction(array $overrides): array
    {
        $this->client->request('POST', '/api/v1/transactions', server: self::jsonHeaders(), content: json_encode($this->transactionPayload($overrides), JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->decode();
    }

    /** @param array<string, mixed> $body */
    private function requestTransactionUpdate(string $id, array $body): void
    {
        $this->client->request('PUT', '/api/v1/transactions/'.$id, server: self::jsonHeaders(), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $transaction
     *
     * @return array<string, mixed>
     */
    private function firstSplit(array $transaction): array
    {
        $splits = $transaction['splits'] ?? null;
        self::assertIsList($splits);
        self::assertCount(1, $splits);
        self::assertIsArray($splits[0]);

        return self::associative($splits[0]);
    }

    private function seedTransaction(
        string $id,
        string $bookedOn,
        string $amount,
        string $rawLabel,
        ?string $counterparty = null,
        string $nature = 'EXPENSE',
        string $state = 'BOOKED',
        string $source = 'MANUAL',
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $account = self::OWN_ACCOUNT,
    ): void {
        $this->connection->insert('transaction_transactions', [
            'id' => $id,
            'workspace_id' => $workspace,
            'account_id' => $account,
            'asset_code' => 'EUR',
            'amount_value' => $amount,
            'amount_scale' => 2,
            'state' => $state,
            'nature' => $nature,
            'source' => $source,
            'source_ref' => 'MANUAL' === $source ? null : 'import-'.$id,
            'booked_on' => $bookedOn,
            'raw_label' => $rawLabel,
            'counterparty' => $counterparty,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
            'voided_at' => 'VOIDED' === $state ? '2026-03-14 09:12:04+00' : null,
        ]);
    }

    private function seedSplit(string $transactionId, string $categoryId, string $amount): void
    {
        $this->connection->insert('transaction_splits', [
            'id' => '00000000-0000-7000-8000-0000000002'.substr($transactionId, -2),
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'transaction_id' => $transactionId,
            'category_id' => $categoryId,
            'amount_value' => $amount,
            'amount_scale' => 2,
            'asset_code' => 'EUR',
            'created_at' => '2026-03-14 09:12:04+00',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function splitRows(string $transactionId, string $workspace = WorkspaceFixture::OWN_WORKSPACE): array
    {
        /* @var list<array<string, mixed>> */
        return $this->connection->fetchAllAssociative(
            'SELECT category_id, categorization_origin, categorization_rule_id, analytic_axes::text AS analytic_axes FROM transaction_splits WHERE workspace_id = ? AND transaction_id = ? ORDER BY position',
            [$workspace, $transactionId],
        );
    }

    private function transactionVersion(string $id): int
    {
        return (int) self::text(['v' => $this->connection->fetchOne('SELECT version FROM transaction_transactions WHERE id = ?', [$id])], 'v');
    }

    private function counterparty(string $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT counterparty FROM transaction_transactions WHERE id = ?', [$id]);

        return null === $value ? null : self::text(['c' => $value], 'c');
    }

    private function appliedCount(string $ruleId): int
    {
        return (int) self::text(['n' => $this->connection->fetchOne('SELECT applied_count FROM transaction_categorization_rules WHERE id = ?', [$ruleId])], 'n');
    }

    private function ruleVersion(string $ruleId): int
    {
        return (int) self::text(['n' => $this->connection->fetchOne('SELECT version FROM transaction_categorization_rules WHERE id = ?', [$ruleId])], 'n');
    }

    /** @return array{bool, ?string} */
    private function ruleState(string $ruleId): array
    {
        $row = $this->connection->fetchAssociative('SELECT active, deactivated_reason FROM transaction_categorization_rules WHERE id = ?', [$ruleId]);
        self::assertIsArray($row);

        return [(bool) $row['active'], null === $row['deactivated_reason'] ? null : self::text($row, 'deactivated_reason')];
    }

    private function ruleCount(): int
    {
        return (int) self::text(['n' => $this->connection->fetchOne('SELECT count(*) FROM transaction_categorization_rules')], 'n');
    }

    private function seedAccount(string $id, string $workspace, string $label, bool $archived = false): void
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
            'opened_on' => '2025-01-01',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00',
            'updated_at' => '2026-03-14 09:12:04+00',
            'archived_at' => $archived ? '2026-03-14 09:12:04+00' : null,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    private function seedCategory(string $id, string $workspace, string $type, string $label, bool $archived = false): void
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
            'archived_at' => $archived ? '2026-03-14 09:12:04+00' : null,
        ]);
    }

    private function signIn(string $email = WorkspaceFixture::OWNER_EMAIL): void
    {
        $this->client->request('POST', '/api/v1/session', server: self::jsonHeaders(), content: json_encode([
            'email' => $email,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return self::associative($decoded);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $page): array
    {
        $items = $page['items'] ?? null;
        self::assertIsList($items);

        $result = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $result[] = self::associative($item);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $preview
     *
     * @return list<array<string, mixed>>
     */
    private function samples(array $preview): array
    {
        $samples = $preview['samples'] ?? null;
        self::assertIsList($samples);

        $result = [];
        foreach ($samples as $sample) {
            self::assertIsArray($sample);
            $result[] = self::associative($sample);
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function associative(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Expected an object-shaped response.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data);
        self::assertTrue(is_string($data[$key]) || is_int($data[$key]));

        return (string) $data[$key];
    }

    /** @return array<string, string> */
    private static function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }
}

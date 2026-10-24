<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Kernel;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * ARC-002 rule 6: every route that names a resource of the caller's workspace answers the
 * standard 404 problem, byte for byte like an unknown identifier, when the resource belongs
 * to another workspace.
 *
 * The cases come from the router, not from a hand list. Each path parameter is classified by
 * the segment before it; a parameter nobody classified fails the test, so a new identifier
 * route cannot ship without a seeded resource of that type in the second workspace.
 */
final class ForeignWorkspaceAccessContractTest extends WebTestCase
{
    /**
     * Parameters that are not owned resources, keyed by the full route template and the parameter,
     * with the reason. Everything else must be a seeded identifier type below.
     */
    private const array NOT_A_RESOURCE = [
        '/api/v1/periods/{period}#period' => 'a calendar month of the caller workspace, not an identifier',
        '/api/v1/periods/{period}/closure#period' => 'a calendar month of the caller workspace, not an identifier',
        '/api/v1/periods/{period}/reopening#period' => 'a calendar month of the caller workspace, not an identifier',
        '/api/v1/reports/annual/{year}#year' => 'a calendar year of the caller workspace, not an identifier',
        '/api/v1/reports/annual/{year}/columns/{columnId}/explain#year' => 'a calendar year of the caller workspace, not an identifier',
        '/api/v1/reports/annual/{year}/columns/{columnId}/explain#columnId' => 'a report column key, resolved inside the caller report',
        '/api/v1/reports/monthly/{kpi}/explain#kpi' => 'a fixed KPI code',
        '/api/v1/assets/{code}#code' => 'a system catalogue code, shared by every workspace by design',
        '/api/v1/products/{code}#code' => 'a system catalogue code, shared by every workspace by design',
    ];

    /** Previous path segment => seeded identifier type. */
    private const array TYPE_BY_SEGMENT = [
        'account-groups' => 'accountGroup',
        'overview' => 'accountGroup',
        'audit-events' => 'auditEvent',
        'balances' => 'snapshot',
        'budget-plans' => 'budgetPlan',
        'budget-targets' => 'budgetTarget',
        'comparisons' => 'budgetTarget',
        'categories' => 'category',
        'categorization-rules' => 'rule',
        'dismissals' => 'dismissal',
        'goals' => 'goal',
        'instruments' => 'instrument',
        'portfolio-positions' => 'instrument',
        'life-insurance-euro-fund-rates' => 'euroFundRate',
        'life-insurance-fee-terms' => 'feeTerm',
        'portfolio-operations' => 'operation',
        'portfolio-valuations' => 'valuation',
        'product-models' => 'productModel',
        'recurrences' => 'recurrence',
        'rule-overrides' => 'override',
        'transactions' => 'transaction',
        'transfers' => 'transfer',
    ];

    private const string GROUP = '00000000-0000-7000-8000-00000000f001';
    private const string CATEGORY = '00000000-0000-7000-8000-00000000f002';
    private const string ACCOUNT = '00000000-0000-7000-8000-00000000f003';
    private const string PORTFOLIO_ACCOUNT = '00000000-0000-7000-8000-00000000f004';
    private const string INSURANCE_ACCOUNT = '00000000-0000-7000-8000-00000000f005';
    private const string INSTRUMENT = '00000000-0000-7000-8000-00000000f006';
    private const string EURO_FUND = '00000000-0000-7000-8000-00000000f007';
    private const string PLAN = '00000000-0000-7000-8000-00000000f008';
    private const string TARGET = '00000000-0000-7000-8000-00000000f009';
    private const string RULE = '00000000-0000-7000-8000-00000000f00a';
    private const string GOAL = '00000000-0000-7000-8000-00000000f00b';
    private const string RECURRENCE = '00000000-0000-7000-8000-00000000f00c';
    private const string TRANSACTION = '00000000-0000-7000-8000-00000000f00d';
    private const string TRANSFER = '00000000-0000-7000-8000-00000000f00e';
    private const string TRANSFER_SOURCE = '00000000-0000-7000-8000-00000000f00f';
    private const string TRANSFER_TARGET = '00000000-0000-7000-8000-00000000f010';
    private const string PRODUCT_MODEL = '00000000-0000-7000-8000-00000000f011';
    private const string OVERRIDE = '00000000-0000-7000-8000-00000000f012';
    private const string OPERATION = '00000000-0000-7000-8000-00000000f013';
    private const string VALUATION = '00000000-0000-7000-8000-00000000f014';
    private const string AUDIT_EVENT = '00000000-0000-7000-8000-00000000f015';
    private const string CONTRACT = '00000000-0000-7000-8000-00000000f016';
    private const string EURO_FUND_RATE = '00000000-0000-7000-8000-00000000f017';
    private const string FEE_TERM = '00000000-0000-7000-8000-00000000f018';
    private const string GOAL_VERSION = '00000000-0000-7000-8000-00000000f019';
    private const string DISMISSAL = '00000000-0000-7000-8000-00000000f01a';
    private const string SNAPSHOT = '00000000-0000-7000-8000-00000000f01b';
    private const string FINGERPRINT = 'abababababababababababababababababababababababababababababababab';
    private const string UNKNOWN_FINGERPRINT = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';
    private const string UNKNOWN = '00000000-0000-7000-8000-00000000ffff';
    // Resources of the caller workspace that request bodies may legitimately reference.
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-00000000e001';
    private const string OWN_SECOND_ACCOUNT = '00000000-0000-7000-8000-00000000e002';
    private const string OWN_CATEGORY = '00000000-0000-7000-8000-00000000e003';
    private const string OWN_EURO_FUND = '00000000-0000-7000-8000-00000000e004';
    private const string OWN_PORTFOLIO_ACCOUNT = '00000000-0000-7000-8000-00000000e005';
    private const string OWN_INSTRUMENT = '00000000-0000-7000-8000-00000000e006';
    private const string OWN_OVERRIDE = '00000000-0000-7000-8000-00000000e007';
    private const string OWN_PLAN = '00000000-0000-7000-8000-00000000e008';
    private const string OWN_TARGET = '00000000-0000-7000-8000-00000000e009';
    private const string OWN_SNAPSHOT = '00000000-0000-7000-8000-00000000e00a';
    private const string AT = '2026-09-01 12:00:00+00';

    private KernelBrowser $client;
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    /**
     * @return iterable<string, array{method: string, path: string, types: array<string, string>, only: ?string}>
     */
    public static function foreignResourceRoutes(): iterable
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $router = $kernel->getContainer()->get('router');
            \assert($router instanceof RouterInterface);
            $cases = [];
            foreach ($router->getRouteCollection() as $route) {
                if (!str_starts_with($route->getPath(), '/api/v1/')) {
                    continue;
                }
                foreach ($route->getMethods() ?: ['GET'] as $method) {
                    foreach (self::expandKinds($route->getPath(), $route->getRequirement('kind')) as $path) {
                        $types = self::classify($path);
                        if ([] === $types) {
                            continue;
                        }
                        $cases[$method.' '.$path] = ['method' => $method, 'path' => $path, 'types' => $types, 'only' => null];
                        if (\count($types) > 1) {
                            // One foreign parameter among the caller's own resources: each id must be checked on its own.
                            foreach (array_keys($types) as $name) {
                                $cases[$method.' '.$path.' [foreign {'.$name.'}]'] = ['method' => $method, 'path' => $path, 'types' => $types, 'only' => $name];
                            }
                        }
                    }
                }
            }
            ksort($cases);
        } finally {
            $kernel->shutdown();
        }

        yield from $cases;
    }

    /** @return list<string> */
    private static function expandKinds(string $path, ?string $kindRequirement): array
    {
        if (!str_contains($path, '{kind}')) {
            return [$path];
        }

        return array_map(
            static fn (string $kind): string => str_replace('{kind}', $kind, $path),
            explode('|', $kindRequirement ?? ''),
        );
    }

    /**
     * Parameter name => identifier type, for the resource parameters of a path.
     *
     * @return array<string, string>
     */
    private static function classify(string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        $types = [];
        foreach ($segments as $index => $segment) {
            if (1 !== preg_match('/^\{(\w+)\}$/', $segment, $match)) {
                continue;
            }
            $name = $match[1];
            if (isset(self::NOT_A_RESOURCE[$path.'#'.$name])) {
                continue;
            }
            $previous = $segments[$index - 1] ?? '';
            $types[$name] = match (true) {
                'accounts' === $previous && str_contains($path, 'life-insurance') => 'insuranceAccount',
                'accounts' === $previous && str_contains($path, 'portfolio-') => 'portfolioAccount',
                'accounts' === $previous => 'account',
                'budget-plans' === $previous => 'budgetPlan',
                // A ledger identifier is a category for income and expense, an account otherwise.
                'income' === $previous || 'expense' === $previous => 'category',
                'account' === $previous => 'account',
                default => self::TYPE_BY_SEGMENT[$previous] ?? 'unclassified:'.$name,
            };
        }

        return $types;
    }

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
        $this->seedOwnWorkspace();
        $this->seedOtherWorkspace();

        $this->client->request('GET', '/api/v1/session');
        $csrf = $this->client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', $csrf->getValue());
        $this->client->request('POST', '/api/v1/session', server: self::headers(), content: json_encode([
            'email' => WorkspaceFixture::OWNER_EMAIL,
            'password' => WorkspaceFixture::OWNER_PASSWORD,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testEveryIdentifierTypeIsSeeded(): void
    {
        $seeded = array_keys($this->identifiers());
        $needed = [];
        foreach (self::foreignResourceRoutes() as $case) {
            array_push($needed, ...array_values($case['types']));
        }

        self::assertSame([], array_values(array_diff(array_unique($needed), $seeded)), 'Seed one resource of each missing identifier type in the other workspace.');
    }

    /** @param array<string, string> $types */
    #[DataProvider('foreignResourceRoutes')]
    public function testAResourceOfAnotherWorkspaceAnswersLikeAnUnknownOne(string $method, string $path, array $types, ?string $only): void
    {
        $known = $this->identifiers();
        $own = $this->ownIdentifiers();
        $foreign = $unknown = [];
        foreach ($types as $name => $type) {
            self::assertArrayHasKey($type, $known, sprintf('%s %s: the {%s} identifier has no seeded resource in the other workspace.', $method, $path, $name));
            if (null !== $only && $name !== $only) {
                // The other identifiers of the route are the caller's own resources, so only {$only} can fail.
                self::assertArrayHasKey($type, $own, sprintf('%s %s: the {%s} identifier has no seeded resource of the caller workspace.', $method, $path, $name));
                $foreign[$name] = $unknown[$name] = $own[$type];
                continue;
            }
            $foreign[$name] = $known[$type];
            $unknown[$name] = 64 === \strlen($known[$type]) ? self::UNKNOWN_FINGERPRINT : self::UNKNOWN;
        }

        $this->call($method, $path, $foreign);
        self::assertResponseStatusCodeSame(404, sprintf('%s %s must hide a resource of another workspace.', $method, $path));
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $foreignBody = (string) $this->client->getResponse()->getContent();
        $problem = json_decode($foreignBody, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(404, $problem['status'] ?? null);
        self::assertIsString($problem['type'] ?? null);
        self::assertIsString($problem['title'] ?? null);

        if (null !== $only) {
            $unknown[$only] = 64 === \strlen($known[$types[$only]]) ? self::UNKNOWN_FINGERPRINT : self::UNKNOWN;
            $foreign[$only] = $known[$types[$only]];
        }
        $this->call($method, $path, $unknown);
        self::assertResponseStatusCodeSame(404);
        // The body may echo the identifier of the request, never anything that tells the cases apart.
        $replaced = null === $only
            ? str_replace(array_values($unknown), array_values($foreign), (string) $this->client->getResponse()->getContent())
            : str_replace($unknown[$only], $foreign[$only], (string) $this->client->getResponse()->getContent());
        self::assertSame($foreignBody, $replaced, sprintf('%s %s answers differently for a foreign resource and an unknown one.', $method, $path));
    }

    /** @param array<string, string> $identifiers */
    private function call(string $method, string $template, array $identifiers): void
    {
        $path = $template;
        foreach ($identifiers as $name => $value) {
            $path = str_replace('{'.$name.'}', $value, $path);
        }
        $path = preg_replace(['/\{period\}/', '/\{year\}/', '/\{kpi\}/', '/\{columnId\}/', '/\{code\}/'], ['2026-09', '2026', 'net_savings', 'income', 'EUR'], $path);
        self::assertIsString($path);
        $body = self::bodyFor($method, $template);
        $body = null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $this->client->request($method, $path.('GET' === $method ? self::queryFor($template) : ''), server: self::headers(), content: $body);
    }

    /**
     * A body that passes validation, so that the 404 is the first thing to go wrong: it references
     * only resources of the caller workspace, the resource under test being the one in the path.
     *
     * @return array<string, mixed>|\stdClass|null
     */
    private static function bodyFor(string $method, string $template): array|\stdClass|null
    {
        $eur = static fn (string $value): array => ['value' => $value, 'assetCode' => 'EUR'];
        $ratePeriod = [
            'kind' => 'ANNUAL_RATE', 'amount' => null, 'amountAssetCode' => null, 'text' => null, 'rateApplication' => 'MARGINAL',
            'brackets' => [['lowerBound' => '0', 'upperBound' => null, 'percentage' => '4']], 'validFrom' => '2027-01-01', 'validTo' => null,
        ];
        $transaction = [
            'accountId' => self::OWN_ACCOUNT, 'amount' => $eur('-42.90'), 'nature' => 'EXPENSE', 'state' => 'BOOKED', 'bookedOn' => '2026-03-14',
            'valueOn' => null, 'authorizedOn' => null, 'rawLabel' => 'CB CARREFOUR 1234', 'counterparty' => 'Carrefour', 'note' => null,
            'paymentMethod' => 'CARD', 'mcc' => null, 'maskedCard' => null, 'bankReference' => null, 'categoryId' => null, 'splits' => null,
        ];
        $operation = ['type' => 'DEPOSIT', 'tradeDate' => '2026-03-02', 'grossAmount' => $eur('10')];

        return match ($method.' '.$template) {
            'POST /api/v1/budget-plans/{id}/activate', 'POST /api/v1/budget-plans/{id}/close',
            'POST /api/v1/accounts/{id}/rule-overrides/{overrideId}/withdraw', 'POST /api/v1/transactions/{id}/duplicate' => new \stdClass(),
            'PATCH /api/v1/accounts/{id}/balances/{snapshotId}' => ['amount' => '1.00', 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => 1],
            'POST /api/v1/accounts/{id}/balances/{snapshotId}/withdraw', 'DELETE /api/v1/budget-targets/{id}' => ['version' => 1],
            'POST /api/v1/budget-plans/{planId}/targets' => ['scopeType' => 'AXIS', 'scopeId' => 'ESSENTIAL', 'valueType' => 'AMOUNT', 'amount' => '300.00', 'ratio' => null],
            'PUT /api/v1/budget-plans/{id}' => ['periodType' => 'YEAR', 'period' => '2027', 'assetCode' => 'USD', 'version' => 1],
            'PUT /api/v1/budget-targets/{id}' => ['valueType' => 'AMOUNT', 'amount' => '450.125', 'ratio' => null, 'version' => 1],
            'PUT /api/v1/account-groups/{id}' => ['label' => 'Changed', 'parentId' => null, 'sortOrder' => 0, 'version' => 1],
            'PUT /api/v1/accounts/{id}' => [
                'label' => 'Changed', 'kind' => 'SAVINGS', 'productCode' => null, 'productModelId' => null, 'institution' => null, 'maskedIdentifier' => null,
                'valuationMode' => 'TRANSACTIONS', 'liquidityLevel' => 'IMMEDIATE', 'includeInNetWorth' => true, 'includeInEmergencyFund' => false,
                'openedOn' => '2026-01-10', 'closedOn' => null, 'version' => 1, 'primaryGroupId' => null, 'tagGroupIds' => [],
            ],
            'POST /api/v1/accounts/{id}/balances' => ['asOf' => '2026-03-31', 'amount' => '10.00', 'amountAssetCode' => 'EUR', 'comment' => null, 'version' => null],
            'POST /api/v1/accounts/{id}/rule-overrides' => [
                'kind' => 'DEPOSIT_CEILING', 'amount' => '30000', 'amountAssetCode' => 'EUR', 'text' => null, 'rateApplication' => null, 'brackets' => [],
                'validFrom' => '2026-01-01', 'validTo' => null, 'reason' => 'The branch confirmed a higher ceiling in writing.',
            ],
            'POST /api/v1/accounts/{id}/move' => ['groupId' => null, 'beforeAccountId' => null, 'orderVersion' => 0],
            'POST /api/v1/categories/{id}/merge' => ['targetId' => self::OWN_CATEGORY, 'version' => 1],
            'POST /api/v1/categories/{id}/move' => ['parentId' => self::OWN_CATEGORY, 'version' => 1],
            'POST /api/v1/categories/{id}/replacement' => ['targetId' => self::OWN_CATEGORY, 'effectiveFrom' => '2026-09-01', 'version' => 1],
            'PUT /api/v1/categories/{id}' => ['type' => 'EXPENSE', 'label' => 'Changed', 'icon' => 'utensils', 'color' => '#AABBCC', 'defaultAnalyticAxes' => ['ESSENTIAL'], 'budgetIncluded' => true, 'sortOrder' => 10, 'version' => 1],
            'PUT /api/v1/categorization-rules/{id}' => [
                'label' => 'Changed', 'priority' => 10, 'accountScope' => [],
                'conditions' => ['text' => ['combinator' => 'AND', 'predicates' => [['source' => 'RAW_LABEL', 'operator' => 'CONTAINS', 'value' => 'CARREFOUR', 'negated' => false]]], 'amount' => ['min' => '-250.00', 'max' => '-5.00', 'assetCode' => 'EUR'], 'direction' => 'OUT'],
                'targetCategoryId' => self::OWN_CATEGORY, 'targetAxes' => ['ESSENTIAL'], 'targetCounterparty' => null, 'effectiveFrom' => '2026-01-01', 'effectiveTo' => null, 'active' => true, 'version' => 1,
            ],
            'PUT /api/v1/goals/{id}' => ['label' => 'Changed', 'targetAmount' => $eur('15000.12'), 'ratio' => null, 'version' => 1],
            'POST /api/v1/goals/{id}/correction-preview' => ['label' => 'Changed', 'targetAmount' => $eur('18000.00'), 'ratio' => null, 'version' => 1, 'effectiveFrom' => '2026-01', 'reason' => 'Correct the target recorded at creation.'],
            'PUT /api/v1/instruments/{id}' => ['name' => 'Changed', 'isin' => null, 'ticker' => 'CHG', 'venueMic' => null, 'instrumentType' => 'ETF', 'assetClass' => null, 'region' => null, 'currencyCode' => 'EUR', 'quantityPrecision' => 6, 'pricePrecision' => 4, 'version' => 1],
            'POST /api/v1/product-models/{id}/duplicate' => ['name' => 'Copy'],
            'POST /api/v1/product-models/{id}/rules' => [...$ratePeriod, 'version' => 1],
            'PUT /api/v1/recurrences/{id}' => ['label' => 'Changed', 'counterparty' => null, 'expectedAmount' => '-14.99', 'amountTolerance' => '0.30', 'intervalKind' => 'MONTHLY', 'dayOfPeriod' => 20, 'version' => 1],
            'PUT /api/v1/transactions/{id}' => [...$transaction, 'version' => 1],
            'PUT /api/v1/transactions/{id}/splits' => ['version' => 1, 'splits' => [['categoryId' => self::OWN_CATEGORY, 'amount' => $eur('-42.90'), 'analyticAxes' => [], 'note' => null]]],
            'POST /api/v1/transactions/{id}/refunds' => ['accountId' => self::OWN_ACCOUNT, 'amount' => $eur('10.00'), 'bookedOn' => '2026-03-14', 'rawLabel' => 'Refund', 'counterparty' => null, 'note' => null],
            'POST /api/v1/transactions/{id}/reconcile' => ['version' => 1, 'matchedTransactionId' => null],
            'PUT /api/v1/transfers/{id}' => [
                'sourceAccountId' => self::OWN_ACCOUNT, 'targetAccountId' => self::OWN_SECOND_ACCOUNT, 'state' => 'BOOKED', 'bookedOn' => '2026-03-14', 'valueOn' => null, 'label' => 'Transfer', 'note' => null, 'fee' => null, 'version' => 1,
            ],
            'POST /api/v1/accounts/{accountId}/reconciliation' => ['snapshotId' => self::UNKNOWN, 'snapshotVersion' => 1, 'periodStart' => '2026-04-01', 'resolution' => 'OVERRIDE'],
            'POST /api/v1/accounts/{accountId}/portfolio-operations', 'POST /api/v1/accounts/{accountId}/portfolio-operations/preview' => $operation,
            'POST /api/v1/portfolio-operations/{id}/correct' => ['version' => 1, 'operation' => $operation],
            'PUT /api/v1/accounts/{accountId}/portfolio-operations/order' => ['tradeDate' => '2026-03-02', 'operationIds' => [self::UNKNOWN]],
            'POST /api/v1/accounts/{accountId}/portfolio-valuations' => ['valuedOn' => '2026-03-31', 'detail' => 'TOTAL', 'totalValue' => $eur('10.00')],
            'PUT /api/v1/accounts/{accountId}/life-insurance-contract' => ['insurer' => 'Assureur', 'distributor' => null, 'commercialName' => 'Contrat', 'contractNumber' => 'AV-00012345', 'subscribedOn' => '2024-02-10', 'managementMode' => 'FREE'],
            'POST /api/v1/accounts/{accountId}/life-insurance-contract/fee-terms' => ['validFrom' => '2026-01-01', 'entryFeeRate' => '1', 'euroFundManagementFeeRate' => '0.60', 'unitLinkedManagementFeeRate' => '0.85', 'switchFeeRate' => '0.50', 'switchFeeFixed' => $eur('5.00'), 'sourceNote' => 'Terms'],
            'POST /api/v1/accounts/{accountId}/life-insurance-contract/euro-fund-rates' => ['instrumentId' => self::OWN_EURO_FUND, 'year' => 2025, 'servedRate' => '2.50', 'basis' => 'NET_OF_MANAGEMENT_FEES', 'sourceNote' => 'Statement'],
            'DELETE /api/v1/life-insurance-euro-fund-rates/{id}', 'DELETE /api/v1/life-insurance-fee-terms/{id}', 'DELETE /api/v1/recurrence-candidates/dismissals/{fingerprint}' => null,
            default => 'GET' === $method ? null : ['version' => 1],
        };
    }

    /** Query string that makes a GET valid before the resource is looked up. */
    private static function queryFor(string $template): string
    {
        return match ($template) {
            '/api/v1/accounts/{accountId}/life-insurance-income-and-fees' => '?year=2026',
            '/api/v1/accounts/{accountId}/portfolio-transfer-candidates' => '?direction=IN',
            '/api/v1/accounts/{accountId}/reconciliation' => '?snapshotId='.self::UNKNOWN.'&periodStart=2026-04-01',
            '/api/v1/categories/{id}/impact' => '?operation=ARCHIVE',
            '/api/v1/reports/monthly/ledger/account/{id}/movements',
            '/api/v1/reports/monthly/ledger/expense/{id}/movements',
            '/api/v1/reports/monthly/ledger/income/{id}/movements' => '?month=2026-09',
            default => '',
        };
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    /** @return array<string, string> identifier type => seeded identifier in the caller workspace */
    private function ownIdentifiers(): array
    {
        return [
            'account' => self::OWN_ACCOUNT,
            'portfolioAccount' => self::OWN_PORTFOLIO_ACCOUNT,
            'instrument' => self::OWN_INSTRUMENT,
            'override' => self::OWN_OVERRIDE,
            'snapshot' => self::OWN_SNAPSHOT,
            'budgetPlan' => self::OWN_PLAN,
            'budgetTarget' => self::OWN_TARGET,
        ];
    }

    /** @return array<string, string> identifier type => seeded identifier in the other workspace */
    private function identifiers(): array
    {
        return [
            'account' => self::ACCOUNT,
            'portfolioAccount' => self::PORTFOLIO_ACCOUNT,
            'insuranceAccount' => self::INSURANCE_ACCOUNT,
            'accountGroup' => self::GROUP,
            'auditEvent' => self::AUDIT_EVENT,
            'budgetPlan' => self::PLAN,
            'budgetTarget' => self::TARGET,
            'category' => self::CATEGORY,
            'dismissal' => self::FINGERPRINT,
            'euroFundRate' => self::EURO_FUND_RATE,
            'feeTerm' => self::FEE_TERM,
            'goal' => self::GOAL,
            'instrument' => self::INSTRUMENT,
            'operation' => self::OPERATION,
            'override' => self::OVERRIDE,
            'productModel' => self::PRODUCT_MODEL,
            'recurrence' => self::RECURRENCE,
            'rule' => self::RULE,
            'snapshot' => self::SNAPSHOT,
            'transaction' => self::TRANSACTION,
            'transfer' => self::TRANSFER,
            'valuation' => self::VALUATION,
        ];
    }

    private function seedOwnWorkspace(): void
    {
        $own = WorkspaceFixture::OWN_WORKSPACE;
        $portfolio = new PortfolioFixture($this->connection);
        foreach ([self::OWN_ACCOUNT, self::OWN_SECOND_ACCOUNT] as $account) {
            $portfolio->account($account, $own, 'Own '.substr($account, -4), kind: 'CURRENT', valuationMode: 'TRANSACTIONS');
        }
        $portfolio->euroFund(self::OWN_EURO_FUND, $own);
        $portfolio->account(self::OWN_PORTFOLIO_ACCOUNT, $own, 'Own portfolio');
        $portfolio->instrument(self::OWN_INSTRUMENT, $own, 'Own ETF', isin: 'IE00B4L5Y984');
        $this->connection->insert('account_rule_overrides', ['id' => self::OWN_OVERRIDE, 'account_id' => self::OWN_ACCOUNT, 'workspace_id' => $own, 'rule_kind' => 'DEPOSIT_CEILING', 'amount_value' => '30000', 'amount_asset' => 'EUR', 'valid_from' => '2026-01-01', 'valid_to' => null, 'reason' => 'Negotiated with the branch.', 'author_id' => WorkspaceFixture::OWNER_ID, 'recorded_at' => self::AT]);
        $this->connection->insert('account_balance_snapshots', ['id' => self::OWN_SNAPSHOT, 'workspace_id' => $own, 'account_id' => self::OWN_ACCOUNT, 'as_of' => '2026-03-31', 'amount_value' => '10', 'amount_literal' => '10', 'amount_asset' => 'EUR', 'source' => 'MANUAL', 'reconciliation_status' => 'UNRECONCILED', 'comment' => null, 'version' => 1, 'recorded_at' => self::AT, 'recorded_by' => WorkspaceFixture::OWNER_ID, 'superseded_at' => null]);
        $this->connection->insert('category_categories', ['id' => self::OWN_CATEGORY, 'workspace_id' => $own, 'type' => 'EXPENSE', 'label' => 'Own', 'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT, 'archived_at' => null], ['budget_included' => ParameterType::BOOLEAN]);
        $this->connection->insert('budget_plans', ['id' => self::OWN_PLAN, 'workspace_id' => $own, 'period_type' => 'MONTH', 'period' => '2026-09-01', 'asset_code' => 'EUR', 'state' => 'DRAFT', 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        $this->connection->insert('budget_targets', ['id' => self::OWN_TARGET, 'workspace_id' => $own, 'plan_id' => self::OWN_PLAN, 'scope_type' => 'CATEGORY', 'scope_id' => self::OWN_CATEGORY, 'value_type' => 'AMOUNT', 'amount_value' => '10', 'amount_scale' => 0, 'ratio_value' => null, 'ratio_scale' => null, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
    }

    private function seedOtherWorkspace(): void
    {
        $other = WorkspaceFixture::OTHER_WORKSPACE;
        $owner = WorkspaceFixture::OTHER_OWNER_ID;
        $db = $this->connection;

        $db->insert('account_groups', ['id' => self::GROUP, 'workspace_id' => $other, 'label' => 'Private group', 'parent_id' => null, 'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        $db->insert('category_categories', ['id' => self::CATEGORY, 'workspace_id' => $other, 'type' => 'EXPENSE', 'label' => 'Private', 'parent_id' => null, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT, 'archived_at' => null], ['budget_included' => ParameterType::BOOLEAN]);

        $portfolio = new PortfolioFixture($db);
        $portfolio->account(self::PORTFOLIO_ACCOUNT, $other, 'Private portfolio');
        $portfolio->account(self::INSURANCE_ACCOUNT, $other, 'Private insurance', kind: 'INSURANCE_CONTRACT');
        $portfolio->instrument(self::INSTRUMENT, $other, 'Private ETF', isin: 'IE00B4L5Y983');
        $portfolio->euroFund(self::EURO_FUND, $other);
        $db->insert('account_financial_accounts', [
            'id' => self::ACCOUNT, 'workspace_id' => $other, 'label' => 'Private account', 'asset_code' => 'EUR', 'kind' => 'SAVINGS',
            'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS', 'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => true,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null, 'version' => 1,
            'created_at' => self::AT, 'updated_at' => self::AT, 'used_at' => null, 'archived_at' => null,
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);

        $db->insert('account_balance_snapshots', ['id' => self::SNAPSHOT, 'workspace_id' => $other, 'account_id' => self::ACCOUNT, 'as_of' => '2026-03-31', 'amount_value' => '10', 'amount_literal' => '10', 'amount_asset' => 'EUR', 'source' => 'MANUAL', 'reconciliation_status' => 'UNRECONCILED', 'comment' => null, 'version' => 1, 'recorded_at' => self::AT, 'recorded_by' => $owner, 'superseded_at' => null]);
        $db->insert('budget_plans', ['id' => self::PLAN, 'workspace_id' => $other, 'period_type' => 'MONTH', 'period' => '2026-09-01', 'asset_code' => 'EUR', 'state' => 'DRAFT', 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        $db->insert('budget_targets', ['id' => self::TARGET, 'workspace_id' => $other, 'plan_id' => self::PLAN, 'scope_type' => 'CATEGORY', 'scope_id' => self::CATEGORY, 'value_type' => 'AMOUNT', 'amount_value' => '10', 'amount_scale' => 0, 'ratio_value' => null, 'ratio_scale' => null, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        $db->insert('transaction_categorization_rules', [
            'id' => self::RULE, 'workspace_id' => $other, 'label' => 'Private rule', 'priority' => 10, 'account_scope' => '[]',
            'conditions' => '{"direction":"OUT"}', 'target_category_id' => self::CATEGORY, 'target_axes' => '[]', 'target_counterparty' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null, 'active' => true, 'deactivated_reason' => null, 'applied_count' => 0,
            'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT, 'archived_at' => null,
        ], ['active' => ParameterType::BOOLEAN]);
        $db->insert('budget_goals', ['id' => self::GOAL, 'workspace_id' => $other, 'label' => 'Private goal', 'kind' => 'ANNUAL', 'metric' => 'NET_SAVINGS_TRANSFERS', 'scope_type' => 'WORKSPACE', 'scope_ref' => null, 'asset_code' => 'EUR', 'state' => 'ACTIVE', 'created_at' => self::AT, 'created_by' => $owner, 'closed_at' => null, 'version' => 1]);
        $db->insert('budget_goal_versions', ['id' => self::GOAL_VERSION, 'workspace_id' => $other, 'goal_id' => self::GOAL, 'version_no' => 1, 'effective_from' => '2026-09', 'year' => 2026, 'target_amount' => '100.00', 'target_scale' => 2, 'ratio' => null, 'ratio_scale' => null, 'schedule_mode' => null, 'reason' => null, 'created_at' => self::AT, 'created_by' => $owner]);

        $db->insert('transaction_recurrences', [
            'id' => self::RECURRENCE, 'workspace_id' => $other, 'account_id' => self::ACCOUNT, 'label' => 'Private rent', 'counterparty' => null,
            'expected_amount_value' => '-500', 'expected_amount_scale' => 0, 'asset_code' => 'EUR', 'amount_tolerance_value' => '0', 'amount_tolerance_scale' => 0,
            'interval_kind' => 'MONTHLY', 'day_of_period' => 5, 'next_expected_on' => '2026-10-05', 'confirmed_at' => self::AT, 'version' => 1,
            'created_at' => self::AT, 'updated_at' => self::AT, 'archived_at' => null,
        ]);
        $db->insert('transaction_recurrence_dismissals', ['id' => self::DISMISSAL, 'workspace_id' => $other, 'candidate_fingerprint' => self::FINGERPRINT, 'dismissed_at' => self::AT]);

        $portfolio->transferLeg(self::TRANSACTION, self::ACCOUNT, '-42.90', '2026-03-14', $other, nature: 'EXPENSE', label: 'Private expense');
        $portfolio->transferLeg(self::TRANSFER_SOURCE, self::ACCOUNT, '-10.00', '2026-03-15', $other);
        $portfolio->transferLeg(self::TRANSFER_TARGET, self::PORTFOLIO_ACCOUNT, '10.00', '2026-03-15', $other);
        $db->insert('transaction_transfers', ['id' => self::TRANSFER, 'workspace_id' => $other, 'source_transaction_id' => self::TRANSFER_SOURCE, 'target_transaction_id' => self::TRANSFER_TARGET, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);

        $db->insert('account_product_models', ['id' => self::PRODUCT_MODEL, 'workspace_id' => $other, 'name' => 'Private model', 'family' => 'SAVINGS', 'wrapper_kind' => 'NONE', 'yield_kind' => 'CONTRACTUAL_FIXED', 'default_group_code' => null, 'valuation_mode' => 'TRANSACTIONS', 'origin' => 'DECLARED', 'derived_from_product_code' => null, 'derived_from_model_id' => null, 'version' => 1, 'created_at' => self::AT, 'updated_at' => self::AT]);
        $db->insert('account_rule_overrides', ['id' => self::OVERRIDE, 'account_id' => self::ACCOUNT, 'workspace_id' => $other, 'rule_kind' => 'DEPOSIT_CEILING', 'amount_value' => '30000', 'amount_asset' => 'EUR', 'valid_from' => '2026-01-01', 'valid_to' => null, 'reason' => 'Negotiated with the branch.', 'author_id' => $owner, 'recorded_at' => self::AT]);

        $db->insert('portfolio_operations', [
            'id' => self::OPERATION, 'workspace_id' => $other, 'account_id' => self::PORTFOLIO_ACCOUNT, 'operation_type' => 'DEPOSIT', 'trade_date' => '2026-03-02',
            'sequence' => 1, 'asset_code' => 'EUR', 'gross_amount_value' => '5', 'gross_amount_scale' => 0, 'fees_value' => '0', 'fees_scale' => 0,
            'taxes_value' => '0', 'taxes_scale' => 0, 'cash_amount_value' => '5', 'cash_amount_scale' => 0, 'state' => 'ACTIVE',
            'created_at' => self::AT, 'created_by' => $owner, 'version' => 1,
        ]);
        $db->insert('portfolio_valuations', [
            'id' => self::VALUATION, 'workspace_id' => $other, 'account_id' => self::PORTFOLIO_ACCOUNT, 'valued_on' => '2026-03-31', 'asset_code' => 'EUR',
            'cash_value' => '0', 'cash_scale' => 0, 'derived_cash_value' => '0', 'derived_cash_scale' => 0, 'cash_source' => 'DERIVED',
            'securities_value_value' => '0', 'securities_value_scale' => 0, 'total_value_value' => '0', 'total_value_scale' => 0,
            'detail' => 'TOTAL', 'completeness' => 'COMPLETE', 'source' => 'MANUAL', 'recorded_at' => self::AT, 'recorded_by' => $owner, 'version' => 1,
        ]);

        $db->insert('portfolio_life_insurance_contracts', [
            'id' => self::CONTRACT, 'workspace_id' => $other, 'account_id' => self::INSURANCE_ACCOUNT, 'insurer' => 'Private insurer',
            'subscribed_on' => '2026-01-01', 'tax_start_on' => '2026-01-01', 'management_mode' => 'FREE', 'status' => 'OPEN',
            'created_at' => self::AT, 'updated_at' => self::AT, 'created_by' => $owner, 'version' => 1,
        ]);
        $db->insert('portfolio_life_insurance_fee_terms', ['id' => self::FEE_TERM, 'workspace_id' => $other, 'contract_id' => self::CONTRACT, 'valid_from' => '2026-01-01', 'recorded_at' => self::AT, 'recorded_by' => $owner]);
        $db->insert('portfolio_euro_fund_rates', ['id' => self::EURO_FUND_RATE, 'workspace_id' => $other, 'contract_id' => self::CONTRACT, 'instrument_id' => self::EURO_FUND, 'year' => 2025, 'served_rate_value' => '2.5', 'served_rate_scale' => 1, 'bonus_rate_value' => null, 'bonus_rate_scale' => null, 'basis' => 'UNKNOWN', 'source_note' => null, 'recorded_at' => self::AT, 'recorded_by' => $owner]);

        $db->insert('audit_events', ['id' => self::AUDIT_EVENT, 'workspace_id' => $other, 'actor_id' => null, 'event_type' => 'workspace.created', 'entity_type' => 'workspace', 'entity_id' => $other, 'before_json' => null, 'after_json' => '{"baseCurrency":"EUR"}', 'occurred_at' => self::AT]);
    }
}

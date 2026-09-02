<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Infrastructure\Persistence;

use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\ProductRule;
use App\Module\Catalog\Domain\RuleKind;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The product catalogue against the migrated PostgreSQL schema: the rows the
 * migration seeds, and the constraints that stop an unsourced, overlapping or
 * misleading rule from ever being stored.
 */
final class ProductCatalogReferenceTest extends KernelTestCase
{
    /** SQLSTATE of a PostgreSQL check-constraint violation. */
    private const string CHECK_VIOLATION = '23514';
    /** SQLSTATE of a PostgreSQL exclusion-constraint violation. */
    private const string EXCLUSION_VIOLATION = '23P01';
    /** SQLSTATE of a PostgreSQL foreign-key violation. */
    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string LIVRET_A_CEILING_SOURCE = '0199c0de-0001-7000-8000-000000000002';

    private Connection $connection;
    private ProductCatalog $catalog;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $catalog = self::getContainer()->get(ProductCatalog::class);
        self::assertInstanceOf(ProductCatalog::class, $catalog);
        $this->catalog = $catalog;

        // The catalogue is written only by a migration, so the tests that probe
        // its constraints must leave the seeded rows exactly as they found them.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheMigrationSeedsTheDocumentedProducts(): void
    {
        $seeded = [];
        foreach ($this->catalog->readPage(100, 0) as $entry) {
            $seeded[$entry->product->code->toString()] = [
                $entry->product->accountKind->value,
                $entry->product->wrapperKind->value,
                $entry->product->yieldKind->value,
            ];
        }

        self::assertSame([
            'FR_CTO' => ['PORTFOLIO', 'SECURITIES_ACCOUNT', 'MARKET'],
            'FR_LDDS' => ['SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE'],
            'FR_LEP' => ['SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE'],
            'FR_LIFE_INSURANCE' => ['INSURANCE_CONTRACT', 'LIFE_INSURANCE', 'MANUAL_VALUATION'],
            'FR_LIVRET_A' => ['SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE'],
            'FR_LIVRET_JEUNE' => ['SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE'],
            'FR_PEA' => ['PORTFOLIO', 'TAX_WRAPPER', 'MARKET'],
            'FR_PEA_PME' => ['PORTFOLIO', 'TAX_WRAPPER', 'MARKET'],
        ], $seeded);
        self::assertSame(8, $this->catalog->count());
    }

    public function testASeededCeilingKeepsItsExactValueAndItsAsset(): void
    {
        $livretA = $this->livretA();
        $ceiling = $this->ruleOfKind($livretA, RuleKind::DEPOSIT_CEILING);

        // NUMERIC(50,24) pads on the way in; the read strips the padding
        // without rounding, so the figure is neither shortened nor inflated.
        self::assertSame('22950', $ceiling->value->amount?->value->toString());
        self::assertSame('EUR', $ceiling->value->amount->asset->toString());
    }

    public function testASeededRateStaysThePercentageTheSourcePublished(): void
    {
        $rate = $this->ruleOfKind($this->livretA(), RuleKind::ANNUAL_RATE);

        self::assertSame('1.7', $rate->value->percentage?->toString());
        self::assertSame('2026-08-01', $rate->period->validFrom->format('Y-m-d'));
        self::assertSame('2027-01-31', $rate->period->validTo?->format('Y-m-d'));
    }

    public function testEverySeededRuleCarriesAnOfficialSourceAndAVerificationTrace(): void
    {
        foreach ($this->catalog->readPage(100, 0) as $entry) {
            foreach ($entry->schedule->rules as $rule) {
                $label = $entry->product->code->toString().'/'.$rule->kind->value;

                self::assertStringStartsWith('https://', $rule->source->url, $label);
                self::assertNotSame('', $rule->source->publisher, $label);
                self::assertNotNull($rule->verifiedOn, $label);
                self::assertNotNull($rule->verifiedBy, $label);
            }
        }
    }

    public function testNoMarketProductCarriesARateRule(): void
    {
        foreach ($this->catalog->readPage(100, 0) as $entry) {
            if ($entry->product->yieldKind->acceptsRateRule()) {
                continue;
            }

            // Enforced by the domain on hydration; asserted here against the
            // seeded rows, because a migration writes past the domain.
            self::assertFalse(
                $entry->schedule->hasRateRule(),
                $entry->product->code->toString().' must never promise a return.',
            );
        }
    }

    public function testASecondRuleOfOneKindCoveringTheSameDayIsRefused(): void
    {
        $this->expectSqlState(self::EXCLUSION_VIOLATION);

        // A catalogue update appends a period. Rewriting one would make "the
        // ceiling on 12 March" ambiguous, so the database refuses the overlap.
        $this->insertCeiling('2026-01-01', null);
    }

    public function testAnEarlierPeriodEndingTheDayBeforeIsAccepted(): void
    {
        $this->insertCeiling('2024-01-01', '2025-04-24');

        $appended = $this->connection->fetchOne(
            "SELECT count(*) FROM catalog_product_rules WHERE product_code = 'FR_LIVRET_A' AND valid_from = DATE '2024-01-01'",
        );
        self::assertIsNumeric($appended);
        self::assertSame(1, (int) $appended);
    }

    #[DataProvider('impossibleRules')]
    public function testARuleThatCouldMisleadIsRefused(string $columns, string $values, string $sqlState): void
    {
        $this->expectSqlState($sqlState);

        $this->connection->executeStatement(
            "INSERT INTO catalog_product_rules (id, product_code, valid_from, {$columns})
             VALUES ('0199c0de-0002-7000-8000-0000000000f1', 'FR_LIVRET_A', DATE '2019-01-01', {$values})",
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function impossibleRules(): iterable
    {
        $source = "'".self::LIVRET_A_CEILING_SOURCE."'";

        yield 'a ceiling written as a rate' => [
            'source_id, rule_kind, percentage_value',
            $source.", 'DEPOSIT_CEILING', 1.7",
            self::CHECK_VIOLATION,
        ];
        yield 'a rate written as an amount' => [
            'source_id, rule_kind, amount_value, amount_asset',
            $source.", 'ANNUAL_RATE', 1.7, 'EUR'",
            self::CHECK_VIOLATION,
        ];
        yield 'an amount with no asset' => [
            'source_id, rule_kind, amount_value',
            $source.", 'DEPOSIT_CEILING', 22950",
            self::CHECK_VIOLATION,
        ];
        yield 'an amount in an unknown asset' => [
            // Otherwise valid, so it carries a closed period that clears the
            // seeded ceiling and reaches the key check it is about.
            'valid_to, source_id, rule_kind, amount_value, amount_asset',
            "DATE '2019-12-31', ".$source.", 'DEPOSIT_CEILING', 22950, 'XXX'",
            self::FOREIGN_KEY_VIOLATION,
        ];
        yield 'a negative ceiling' => [
            'source_id, rule_kind, amount_value, amount_asset',
            $source.", 'DEPOSIT_CEILING', -1, 'EUR'",
            self::CHECK_VIOLATION,
        ];
        yield 'a rate beyond a hundred percent' => [
            'source_id, rule_kind, percentage_value',
            $source.", 'ANNUAL_RATE', 101",
            self::CHECK_VIOLATION,
        ];
        yield 'two values at once' => [
            'source_id, rule_kind, amount_value, amount_asset, percentage_value',
            $source.", 'DEPOSIT_CEILING', 22950, 'EUR', 1.7",
            self::CHECK_VIOLATION,
        ];
        yield 'a french sentence instead of a token' => [
            'source_id, rule_kind, text_value',
            $source.", 'ELIGIBILITY', 'Aucun plafond réglementaire'",
            self::CHECK_VIOLATION,
        ];
        yield 'half a verification trace' => [
            'source_id, rule_kind, amount_value, amount_asset, verified_on',
            $source.", 'DEPOSIT_CEILING', 22950, 'EUR', DATE '2026-08-22'",
            self::CHECK_VIOLATION,
        ];
        yield 'a rule with no source' => [
            'valid_to, source_id, rule_kind, amount_value, amount_asset',
            "DATE '2019-12-31', '0199c0de-0001-7000-8000-0000000000ff', 'DEPOSIT_CEILING', 22950, 'EUR'",
            self::FOREIGN_KEY_VIOLATION,
        ];
    }

    public function testASourceReachedOverPlainHttpIsRefused(): void
    {
        $this->expectSqlState(self::CHECK_VIOLATION);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO catalog_product_sources (id, publisher, title, url, retrieved_on)
            VALUES ('0199c0de-0001-7000-8000-0000000000f1', 'Service-Public.fr', 'Livret A',
                    'http://www.service-public.fr/particuliers/vosdroits/F2365', DATE '2026-08-22')
            SQL);
    }

    public function testTheCatalogueBelongsToNoWorkspace(): void
    {
        $scoped = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND column_name = 'workspace_id'
               AND table_name LIKE 'catalog\\_%'",
        );

        // The mirror of the usual isolation question: a global reference must
        // carry nothing of anyone's, and every session must read the same rows.
        self::assertSame([], $scoped);
    }

    private function livretA(): CatalogEntry
    {
        $entry = $this->catalog->findByCode(ProductCode::fromString('FR_LIVRET_A'));
        self::assertNotNull($entry);

        return $entry;
    }

    private function ruleOfKind(CatalogEntry $entry, RuleKind $kind): ProductRule
    {
        foreach ($entry->schedule->rules as $rule) {
            if ($rule->kind === $kind) {
                return $rule;
            }
        }

        self::fail(sprintf('The seeded catalogue has no %s rule for %s.', $kind->value, $entry->product->code->toString()));
    }

    private function insertCeiling(string $validFrom, ?string $validTo): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_product_rules
                (id, product_code, rule_kind, amount_value, amount_asset, valid_from, valid_to, source_id)
             VALUES ('0199c0de-0002-7000-8000-0000000000f2', 'FR_LIVRET_A', 'DEPOSIT_CEILING', 19125, 'EUR', :from, :to, :source)",
            ['from' => $validFrom, 'to' => $validTo, 'source' => self::LIVRET_A_CEILING_SOURCE],
        );
    }

    private function expectSqlState(string $sqlState): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/SQLSTATE\['.preg_quote($sqlState, '/').'\]/');
    }
}

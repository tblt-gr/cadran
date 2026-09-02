<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Infrastructure\Persistence;

use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
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
    /** SQLSTATE used by the history trigger for a forbidden rewrite. */
    private const string RESTRICT_VIOLATION = '23001';

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

    /**
     * @param array<string, ?string> $expected
     */
    #[DataProvider('seededRules')]
    public function testEverySeededRuleIsPinned(string $id, array $expected): void
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    product_code,
                    yield_kind,
                    rule_kind,
                    trim_scale(amount_value)::text AS amount_value,
                    amount_asset,
                    trim_scale(percentage_value)::text AS percentage_value,
                    text_value,
                    valid_from::text AS valid_from,
                    valid_to::text AS valid_to,
                    source_id::text AS source_id
                FROM catalog_product_rules
                WHERE id = :id
                SQL,
            ['id' => $id],
        );

        self::assertSame($expected, $row);
    }

    /**
     * @return iterable<string, array{string, array<string, ?string>}>
     */
    public static function seededRules(): iterable
    {
        yield 'Livret A ceiling' => ['0199c0de-0002-7000-8000-000000000001', self::seededRule(
            'FR_LIVRET_A', 'REGULATED_RATE', 'DEPOSIT_CEILING', '22950', 'EUR', null, null,
            '2025-04-25', null, '0199c0de-0001-7000-8000-000000000002',
        )];
        yield 'Livret A rate' => ['0199c0de-0002-7000-8000-000000000002', self::seededRule(
            'FR_LIVRET_A', 'REGULATED_RATE', 'ANNUAL_RATE', null, null, '1.7', null,
            '2026-08-01', '2027-01-31', '0199c0de-0001-7000-8000-000000000003',
        )];
        yield 'LDDS ceiling' => ['0199c0de-0002-7000-8000-000000000003', self::seededRule(
            'FR_LDDS', 'REGULATED_RATE', 'DEPOSIT_CEILING', '12000', 'EUR', null, null,
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000004',
        )];
        yield 'LDDS rate' => ['0199c0de-0002-7000-8000-000000000004', self::seededRule(
            'FR_LDDS', 'REGULATED_RATE', 'ANNUAL_RATE', null, null, '1.7', null,
            '2026-08-01', '2027-01-31', '0199c0de-0001-7000-8000-000000000004',
        )];
        yield 'LEP ceiling' => ['0199c0de-0002-7000-8000-000000000005', self::seededRule(
            'FR_LEP', 'REGULATED_RATE', 'DEPOSIT_CEILING', '10000', 'EUR', null, null,
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000005',
        )];
        yield 'LEP rate' => ['0199c0de-0002-7000-8000-000000000006', self::seededRule(
            'FR_LEP', 'REGULATED_RATE', 'ANNUAL_RATE', null, null, '2.5', null,
            '2026-08-01', '2027-01-31', '0199c0de-0001-7000-8000-000000000003',
        )];
        yield 'PEA contribution ceiling' => ['0199c0de-0002-7000-8000-000000000007', self::seededRule(
            'FR_PEA', 'MARKET', 'CONTRIBUTION_CEILING', '150000', 'EUR', null, null,
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000006',
        )];
        yield 'PEA combined ceiling' => ['0199c0de-0002-7000-8000-000000000008', self::seededRule(
            'FR_PEA', 'MARKET', 'COMBINED_CONTRIBUTION_CEILING', '225000', 'EUR', null, null,
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000006',
        )];
        yield 'PEA-PME contribution ceiling' => ['0199c0de-0002-7000-8000-000000000009', self::seededRule(
            'FR_PEA_PME', 'MARKET', 'CONTRIBUTION_CEILING', '225000', 'EUR', null, null,
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000006',
        )];
        yield 'PEA-PME combined ceiling' => ['0199c0de-0002-7000-8000-00000000000a', self::seededRule(
            'FR_PEA_PME', 'MARKET', 'COMBINED_CONTRIBUTION_CEILING', '225000', 'EUR', null, null,
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000006',
        )];
        yield 'CTO absence of ceiling' => ['0199c0de-0002-7000-8000-00000000000b', self::seededRule(
            'FR_CTO', 'MARKET', 'ELIGIBILITY', null, null, null, 'NO_REGULATORY_CONTRIBUTION_CEILING',
            '2026-08-22', null, '0199c0de-0001-7000-8000-000000000007',
        )];
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
            "INSERT INTO catalog_product_rules (id, product_code, yield_kind, valid_from, {$columns})
             VALUES ('0199c0de-0002-7000-8000-0000000000f1', 'FR_LIVRET_A', 'REGULATED_RATE', DATE '2019-01-01', {$values})",
        );
    }

    public function testAMarketProductCannotCarryARateRule(): void
    {
        $this->expectSqlState(self::CHECK_VIOLATION);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO catalog_product_rules
                (id, product_code, yield_kind, rule_kind, percentage_value, valid_from, valid_to, source_id)
            VALUES
                ('0199c0de-0002-7000-8000-0000000000f3', 'FR_PEA', 'MARKET', 'ANNUAL_RATE', 3,
                 DATE '2020-01-01', DATE '2020-12-31', '0199c0de-0001-7000-8000-000000000006')
            SQL);
    }

    public function testARuleCannotLieAboutItsProductsYieldKind(): void
    {
        $this->expectSqlState(self::FOREIGN_KEY_VIOLATION);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO catalog_product_rules
                (id, product_code, yield_kind, rule_kind, percentage_value, valid_from, valid_to, source_id)
            VALUES
                ('0199c0de-0002-7000-8000-0000000000f3', 'FR_PEA', 'REGULATED_RATE', 'ANNUAL_RATE', 3,
                 DATE '2020-01-01', DATE '2020-12-31', '0199c0de-0001-7000-8000-000000000006')
            SQL);
    }

    public function testAnOpenRuleMayOnlyBeClosed(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE catalog_product_rules
            SET valid_to = DATE '2027-01-31'
            WHERE id = '0199c0de-0002-7000-8000-000000000001'
            SQL);

        self::assertSame(
            '2027-01-31',
            $this->connection->fetchOne(
                "SELECT valid_to::text FROM catalog_product_rules WHERE id = '0199c0de-0002-7000-8000-000000000001'",
            ),
        );
    }

    #[DataProvider('historicalRewrites')]
    public function testHistoricalRulesCannotBeRewritten(string $statement): void
    {
        $this->expectSqlState(self::RESTRICT_VIOLATION);

        $this->connection->executeStatement($statement);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function historicalRewrites(): iterable
    {
        yield 'change a value' => [
            "UPDATE catalog_product_rules SET percentage_value = 3 WHERE id = '0199c0de-0002-7000-8000-000000000002'",
        ];
        yield 'change metadata while closing a period' => [
            "UPDATE catalog_product_rules SET valid_to = DATE '2027-01-31', verified_on = DATE '2026-09-02' WHERE id = '0199c0de-0002-7000-8000-000000000001'",
        ];
        yield 'delete a historical row' => [
            "DELETE FROM catalog_product_rules WHERE id = '0199c0de-0002-7000-8000-000000000002'",
        ];
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

    public function testArchivedProductsAreAbsentFromReadsAndTheCount(): void
    {
        $this->connection->executeStatement(
            "UPDATE catalog_products SET archived_at = TIMESTAMPTZ '2026-09-02 12:00:00+00' WHERE code = 'FR_CTO'",
        );

        self::assertNull($this->catalog->findByCode(ProductCode::fromString('FR_CTO')));
        self::assertSame(7, $this->catalog->count());
        self::assertNotContains(
            'FR_CTO',
            array_map(
                static fn (CatalogEntry $entry): string => $entry->product->code->toString(),
                $this->catalog->readPage(100, 0),
            ),
        );
    }

    private function insertCeiling(string $validFrom, ?string $validTo): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_product_rules
                (id, product_code, yield_kind, rule_kind, amount_value, amount_asset, valid_from, valid_to, source_id)
             VALUES ('0199c0de-0002-7000-8000-0000000000f2', 'FR_LIVRET_A', 'REGULATED_RATE', 'DEPOSIT_CEILING', 19125, 'EUR', :from, :to, :source)",
            ['from' => $validFrom, 'to' => $validTo, 'source' => self::LIVRET_A_CEILING_SOURCE],
        );
    }

    /**
     * @return array<string, ?string>
     */
    private static function seededRule(
        string $productCode,
        string $yieldKind,
        string $ruleKind,
        ?string $amountValue,
        ?string $amountAsset,
        ?string $percentageValue,
        ?string $textValue,
        string $validFrom,
        ?string $validTo,
        string $sourceId,
    ): array {
        return [
            'product_code' => $productCode,
            'yield_kind' => $yieldKind,
            'rule_kind' => $ruleKind,
            'amount_value' => $amountValue,
            'amount_asset' => $amountAsset,
            'percentage_value' => $percentageValue,
            'text_value' => $textValue,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source_id' => $sourceId,
        ];
    }

    private function expectSqlState(string $sqlState): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/SQLSTATE\['.preg_quote($sqlState, '/').'\]/');
    }
}

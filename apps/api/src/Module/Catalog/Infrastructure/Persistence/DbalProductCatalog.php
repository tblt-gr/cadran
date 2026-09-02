<?php

declare(strict_types=1);

namespace App\Module\Catalog\Infrastructure\Persistence;

use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\FinancialProduct;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\ProductRule;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\RuleValue;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Read-only access to the product catalogue. Its normalized tables carry no
 * workspace_id: they are a global system reference, identical for every
 * caller, and this class offers no write path — a product, a rule or a source
 * changes through a reviewed migration.
 */
#[AsAlias(ProductCatalog::class)]
final readonly class DbalProductCatalog implements ProductCatalog
{
    private const string PRODUCT_COLUMNS = 'code, display_name, jurisdiction, account_kind, wrapper_kind, yield_kind, default_group_code, catalog_version, archived_at';

    /**
     * trim_scale removes the padding NUMERIC(50,24) adds without altering the
     * value, so a ceiling leaves as `22950` rather than as twenty-four zeros.
     * It is exact: nothing is rounded on the way out.
     */
    private const string RULE_COLUMNS = <<<'SQL'
        r.product_code,
        r.rule_kind,
        trim_scale(r.amount_value)::text AS amount_value,
        r.amount_asset,
        trim_scale(r.percentage_value)::text AS percentage_value,
        r.text_value,
        r.valid_from::text AS valid_from,
        r.valid_to::text AS valid_to,
        r.verified_on::text AS verified_on,
        r.verified_by,
        s.publisher AS source_publisher,
        s.title AS source_title,
        s.url AS source_url,
        s.published_on::text AS source_published_on,
        s.retrieved_on::text AS source_retrieved_on
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function findByCode(ProductCode $code): ?CatalogEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::PRODUCT_COLUMNS.' FROM catalog_products WHERE code = :code AND archived_at IS NULL',
            ['code' => $code->toString()],
        );

        if (false === $row) {
            return null;
        }

        $schedules = $this->readSchedules([$code->toString()]);
        $capabilities = $this->readCapabilities([$code->toString()]);

        return new CatalogEntry(
            self::hydrateProduct($row, ProductCapabilities::fromStrings($capabilities[$code->toString()] ?? [])),
            $schedules[$code->toString()] ?? RuleSchedule::empty(),
        );
    }

    public function readPage(int $limit, int $offset): array
    {
        // The code is the primary key, so ordering on it is both stable and
        // free: two identical requests always return the same page.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::PRODUCT_COLUMNS.' FROM catalog_products WHERE archived_at IS NULL ORDER BY code LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        if ([] === $rows) {
            return [];
        }

        $codes = array_map(
            static fn (array $row): string => self::scalar($row['code'] ?? null),
            $rows,
        );
        $capabilities = $this->readCapabilities($codes);
        $products = array_map(
            static fn (array $row): FinancialProduct => self::hydrateProduct(
                $row,
                ProductCapabilities::fromStrings($capabilities[self::scalar($row['code'] ?? null)] ?? []),
            ),
            $rows,
        );
        $schedules = $this->readSchedules($codes);

        return array_map(
            static fn (FinancialProduct $product): CatalogEntry => new CatalogEntry(
                $product,
                $schedules[$product->code->toString()] ?? RuleSchedule::empty(),
            ),
            $products,
        );
    }

    public function count(): int
    {
        return (int) self::scalar($this->connection->fetchOne('SELECT count(*) FROM catalog_products WHERE archived_at IS NULL'));
    }

    /**
     * Reads every rule of the given products in one round trip, so listing a
     * page costs two queries whatever the page size.
     *
     * @param list<string> $codes
     *
     * @return array<string, RuleSchedule>
     */
    private function readSchedules(array $codes): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::RULE_COLUMNS.'
             FROM catalog_product_rules r
             INNER JOIN catalog_product_sources s ON s.id = r.source_id
             WHERE r.product_code IN (:codes)
             ORDER BY r.product_code, r.rule_kind, r.valid_from',
            ['codes' => $codes],
            ['codes' => ArrayParameterType::STRING],
        );

        /** @var array<string, list<ProductRule>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[self::scalar($row['product_code'] ?? null)][] = self::hydrateRule($row);
        }

        return array_map(static fn (array $rules): RuleSchedule => new RuleSchedule($rules), $grouped);
    }

    /**
     * Reads normalized capability relations in one round trip. A missing set
     * is not defaulted: ProductCapabilities rejects it as an unusable product.
     *
     * @param list<string> $codes
     *
     * @return array<string, list<string>>
     */
    private function readCapabilities(array $codes): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_code, capability_code
             FROM catalog_product_capabilities
             WHERE product_code IN (:codes)
             ORDER BY product_code, capability_code',
            ['codes' => $codes],
            ['codes' => ArrayParameterType::STRING],
        );

        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[self::scalar($row['product_code'] ?? null)][] = self::scalar($row['capability_code'] ?? null);
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateProduct(array $row, ProductCapabilities $capabilities): FinancialProduct
    {
        return new FinancialProduct(
            code: ProductCode::fromString(self::scalar($row['code'] ?? null)),
            displayName: self::scalar($row['display_name'] ?? null),
            jurisdiction: self::nullableScalar($row['jurisdiction'] ?? null),
            accountKind: AccountKind::from(self::scalar($row['account_kind'] ?? null)),
            wrapperKind: WrapperKind::from(self::scalar($row['wrapper_kind'] ?? null)),
            yieldKind: YieldKind::from(self::scalar($row['yield_kind'] ?? null)),
            defaultGroupCode: self::nullableScalar($row['default_group_code'] ?? null),
            capabilities: $capabilities,
            catalogVersion: (int) self::scalar($row['catalog_version'] ?? null),
            archivedAt: self::timestamp($row['archived_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateRule(array $row): ProductRule
    {
        $kind = RuleKind::from(self::scalar($row['rule_kind'] ?? null));
        $verifiedOn = self::day($row['verified_on'] ?? null);

        return new ProductRule(
            kind: $kind,
            value: self::hydrateValue($kind, $row),
            period: new EffectivePeriod(
                validFrom: self::requiredDay($row['valid_from'] ?? null),
                validTo: self::day($row['valid_to'] ?? null),
            ),
            source: new CatalogSource(
                publisher: self::scalar($row['source_publisher'] ?? null),
                title: self::scalar($row['source_title'] ?? null),
                url: self::scalar($row['source_url'] ?? null),
                publishedOn: self::day($row['source_published_on'] ?? null),
                retrievedOn: self::requiredDay($row['source_retrieved_on'] ?? null),
            ),
            verifiedOn: $verifiedOn,
            verifiedBy: self::nullableScalar($row['verified_by'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateValue(RuleKind $kind, array $row): RuleValue
    {
        return match ($kind->valueType()) {
            RuleValueType::AMOUNT => RuleValue::amount(new AssetAmount(
                DecimalValue::fromString(self::scalar($row['amount_value'] ?? null)),
                AssetCode::fromString(self::scalar($row['amount_asset'] ?? null)),
            )),
            RuleValueType::PERCENTAGE => RuleValue::percentage(
                DecimalValue::fromString(self::scalar($row['percentage_value'] ?? null)),
            ),
            RuleValueType::TEXT => RuleValue::text(self::scalar($row['text_value'] ?? null)),
        };
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : self::requiredDay($value);
    }

    private static function requiredDay(mixed $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', self::scalar($value), new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new \UnexpectedValueException('Expected an ISO calendar day from the catalogue.');
        }

        return $date;
    }

    private static function timestamp(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::scalar($value));
    }

    private static function nullableScalar(mixed $value): ?string
    {
        return null === $value ? null : self::scalar($value);
    }

    private static function scalar(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }
}

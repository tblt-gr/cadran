<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds normalized, validated capabilities to the system financial product catalogue.';
    }

    public function up(Schema $schema): void
    {
        // A known capability names an implemented business function. Products
        // reuse these rows through a relation; no product-specific JSON or
        // schema column is introduced when another model uses the same set.
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_capabilities (
                code VARCHAR(32) NOT NULL,
                PRIMARY KEY (code),
                CONSTRAINT catalog_capabilities_code_format
                    CHECK (code ~ '^SUPPORTS_[A-Z]+(_[A-Z]+)*$'),
                CONSTRAINT catalog_capabilities_code_valid CHECK (code IN (
                    'SUPPORTS_BALANCE',
                    'SUPPORTS_TRANSACTIONS',
                    'SUPPORTS_INTEREST',
                    'SUPPORTS_HOLDINGS',
                    'SUPPORTS_TRADES',
                    'SUPPORTS_ARBITRAGE',
                    'SUPPORTS_CONTRIBUTIONS',
                    'SUPPORTS_FEES',
                    'SUPPORTS_TAX_TRACKING',
                    'SUPPORTS_LIABILITY'
                ))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_capabilities (
                product_code VARCHAR(32) NOT NULL,
                capability_code VARCHAR(32) NOT NULL,
                PRIMARY KEY (product_code, capability_code),
                CONSTRAINT catalog_product_capabilities_product_exists
                    FOREIGN KEY (product_code) REFERENCES catalog_products (code),
                CONSTRAINT catalog_product_capabilities_capability_exists
                    FOREIGN KEY (capability_code) REFERENCES catalog_capabilities (code)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_catalog_product_capabilities_capability ON catalog_product_capabilities (capability_code, product_code)');

        // Constraint triggers are deferred: a migration can insert a product
        // and its capability rows in either order inside one transaction, but
        // PostgreSQL will not commit an incomplete or privilege-like set.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION catalog_assert_product_capabilities(target_code VARCHAR) RETURNS VOID AS $$
            DECLARE
                target_kind VARCHAR;
                dependency RECORD;
                required_for_rule RECORD;
            BEGIN
                SELECT account_kind INTO target_kind
                FROM catalog_products
                WHERE code = target_code;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = target_code
                ) THEN
                    RAISE EXCEPTION 'Product % declares at least one capability.', target_code
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT requirements.capability_code, requirements.required_code
                INTO dependency
                FROM (VALUES
                    ('SUPPORTS_INTEREST', 'SUPPORTS_BALANCE'),
                    ('SUPPORTS_HOLDINGS', 'SUPPORTS_BALANCE'),
                    ('SUPPORTS_TRADES', 'SUPPORTS_HOLDINGS'),
                    ('SUPPORTS_TRADES', 'SUPPORTS_TRANSACTIONS'),
                    ('SUPPORTS_ARBITRAGE', 'SUPPORTS_HOLDINGS'),
                    ('SUPPORTS_ARBITRAGE', 'SUPPORTS_TRANSACTIONS'),
                    ('SUPPORTS_CONTRIBUTIONS', 'SUPPORTS_TRANSACTIONS'),
                    ('SUPPORTS_FEES', 'SUPPORTS_TRANSACTIONS'),
                    ('SUPPORTS_TAX_TRACKING', 'SUPPORTS_TRANSACTIONS'),
                    ('SUPPORTS_LIABILITY', 'SUPPORTS_BALANCE')
                ) AS requirements(capability_code, required_code)
                WHERE EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = target_code
                      AND capability_code = requirements.capability_code
                )
                  AND NOT EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = target_code
                      AND capability_code = requirements.required_code
                )
                LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION '% requires %.', dependency.capability_code, dependency.required_code
                        USING ERRCODE = 'check_violation';
                END IF;

                IF target_kind = 'LIABILITY' AND NOT EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = target_code AND capability_code = 'SUPPORTS_LIABILITY'
                ) THEN
                    RAISE EXCEPTION 'A LIABILITY product requires SUPPORTS_LIABILITY.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF target_kind <> 'LIABILITY' AND EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = target_code AND capability_code = 'SUPPORTS_LIABILITY'
                ) THEN
                    RAISE EXCEPTION 'SUPPORTS_LIABILITY is exclusive to LIABILITY products.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT mapped.rule_kind, mapped.capability_code
                INTO required_for_rule
                FROM (VALUES
                    ('DEPOSIT_CEILING', 'SUPPORTS_BALANCE'),
                    ('CONTRIBUTION_CEILING', 'SUPPORTS_CONTRIBUTIONS'),
                    ('COMBINED_CONTRIBUTION_CEILING', 'SUPPORTS_CONTRIBUTIONS'),
                    ('ANNUAL_RATE', 'SUPPORTS_INTEREST'),
                    ('MIN_RATE', 'SUPPORTS_INTEREST'),
                    ('INTEREST_ACCRUAL_METHOD', 'SUPPORTS_INTEREST'),
                    ('TAX_REFERENCE', 'SUPPORTS_TAX_TRACKING')
                ) AS mapped(rule_kind, capability_code)
                WHERE EXISTS (
                    SELECT 1 FROM catalog_product_rules
                    WHERE product_code = target_code AND rule_kind = mapped.rule_kind
                )
                  AND NOT EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = target_code AND capability_code = mapped.capability_code
                )
                LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION '% rules require %.', required_for_rule.rule_kind, required_for_rule.capability_code
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION catalog_product_capabilities_validate_trigger() RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.product_code <> NEW.product_code THEN
                    PERFORM catalog_assert_product_capabilities(OLD.product_code);
                END IF;

                PERFORM catalog_assert_product_capabilities(
                    CASE WHEN TG_OP = 'DELETE' THEN OLD.product_code ELSE NEW.product_code END
                );

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER catalog_product_capabilities_valid
                AFTER INSERT OR UPDATE OR DELETE ON catalog_product_capabilities
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION catalog_product_capabilities_validate_trigger()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION catalog_products_capabilities_validate_trigger() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM catalog_assert_product_capabilities(NEW.code);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER catalog_products_capabilities_valid
                AFTER INSERT OR UPDATE ON catalog_products
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION catalog_products_capabilities_validate_trigger()
            SQL);

        // A rule may activate only behavior declared by the product. This is
        // immediate because capability rows exist before a new historical rule
        // is appended, and a missing capability is a migration error.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION catalog_product_rule_capability_guard() RETURNS TRIGGER AS $$
            DECLARE
                required_capability VARCHAR;
            BEGIN
                -- Let the existing composite foreign key report an unknown
                -- product or a forged yield kind before capability semantics.
                IF NOT EXISTS (
                    SELECT 1 FROM catalog_products
                    WHERE code = NEW.product_code AND yield_kind = NEW.yield_kind
                ) THEN
                    RETURN NEW;
                END IF;

                required_capability := CASE NEW.rule_kind
                    WHEN 'DEPOSIT_CEILING' THEN 'SUPPORTS_BALANCE'
                    WHEN 'CONTRIBUTION_CEILING' THEN 'SUPPORTS_CONTRIBUTIONS'
                    WHEN 'COMBINED_CONTRIBUTION_CEILING' THEN 'SUPPORTS_CONTRIBUTIONS'
                    WHEN 'ANNUAL_RATE' THEN 'SUPPORTS_INTEREST'
                    WHEN 'MIN_RATE' THEN 'SUPPORTS_INTEREST'
                    WHEN 'INTEREST_ACCRUAL_METHOD' THEN 'SUPPORTS_INTEREST'
                    WHEN 'TAX_REFERENCE' THEN 'SUPPORTS_TAX_TRACKING'
                    ELSE NULL
                END;

                IF required_capability IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM catalog_product_capabilities
                    WHERE product_code = NEW.product_code AND capability_code = required_capability
                ) THEN
                    RAISE EXCEPTION '% rules require %.', NEW.rule_kind, required_capability
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER catalog_product_rules_capability_guard
                BEFORE INSERT ON catalog_product_rules
                FOR EACH ROW EXECUTE FUNCTION catalog_product_rule_capability_guard()
            SQL);

        // Seeded last, so the deferred constraint triggers above validate this
        // migration's own capability rows when the transaction commits.
        $this->seedCapabilities();
        $this->seedProductCapabilities();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER catalog_product_rules_capability_guard ON catalog_product_rules');
        $this->addSql('DROP FUNCTION catalog_product_rule_capability_guard()');
        $this->addSql('DROP TRIGGER catalog_products_capabilities_valid ON catalog_products');
        $this->addSql('DROP FUNCTION catalog_products_capabilities_validate_trigger()');
        $this->addSql('DROP TRIGGER catalog_product_capabilities_valid ON catalog_product_capabilities');
        $this->addSql('DROP FUNCTION catalog_product_capabilities_validate_trigger()');
        $this->addSql('DROP FUNCTION catalog_assert_product_capabilities(VARCHAR)');
        $this->addSql('DROP TABLE catalog_product_capabilities');
        $this->addSql('DROP TABLE catalog_capabilities');
    }

    private function seedCapabilities(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO catalog_capabilities (code) VALUES
                ('SUPPORTS_BALANCE'),
                ('SUPPORTS_TRANSACTIONS'),
                ('SUPPORTS_INTEREST'),
                ('SUPPORTS_HOLDINGS'),
                ('SUPPORTS_TRADES'),
                ('SUPPORTS_ARBITRAGE'),
                ('SUPPORTS_CONTRIBUTIONS'),
                ('SUPPORTS_FEES'),
                ('SUPPORTS_TAX_TRACKING'),
                ('SUPPORTS_LIABILITY')
            SQL);
    }

    private function seedProductCapabilities(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO catalog_product_capabilities (product_code, capability_code) VALUES
                ('FR_LIVRET_A', 'SUPPORTS_BALANCE'),
                ('FR_LIVRET_A', 'SUPPORTS_TRANSACTIONS'),
                ('FR_LIVRET_A', 'SUPPORTS_INTEREST'),
                ('FR_LDDS', 'SUPPORTS_BALANCE'),
                ('FR_LDDS', 'SUPPORTS_TRANSACTIONS'),
                ('FR_LDDS', 'SUPPORTS_INTEREST'),
                ('FR_LEP', 'SUPPORTS_BALANCE'),
                ('FR_LEP', 'SUPPORTS_TRANSACTIONS'),
                ('FR_LEP', 'SUPPORTS_INTEREST'),
                ('FR_LIVRET_JEUNE', 'SUPPORTS_BALANCE'),
                ('FR_LIVRET_JEUNE', 'SUPPORTS_TRANSACTIONS'),
                ('FR_LIVRET_JEUNE', 'SUPPORTS_INTEREST'),
                ('FR_PEA', 'SUPPORTS_BALANCE'),
                ('FR_PEA', 'SUPPORTS_TRANSACTIONS'),
                ('FR_PEA', 'SUPPORTS_HOLDINGS'),
                ('FR_PEA', 'SUPPORTS_TRADES'),
                ('FR_PEA', 'SUPPORTS_CONTRIBUTIONS'),
                ('FR_PEA', 'SUPPORTS_FEES'),
                ('FR_PEA', 'SUPPORTS_TAX_TRACKING'),
                ('FR_PEA_PME', 'SUPPORTS_BALANCE'),
                ('FR_PEA_PME', 'SUPPORTS_TRANSACTIONS'),
                ('FR_PEA_PME', 'SUPPORTS_HOLDINGS'),
                ('FR_PEA_PME', 'SUPPORTS_TRADES'),
                ('FR_PEA_PME', 'SUPPORTS_CONTRIBUTIONS'),
                ('FR_PEA_PME', 'SUPPORTS_FEES'),
                ('FR_PEA_PME', 'SUPPORTS_TAX_TRACKING'),
                ('FR_CTO', 'SUPPORTS_BALANCE'),
                ('FR_CTO', 'SUPPORTS_TRANSACTIONS'),
                ('FR_CTO', 'SUPPORTS_HOLDINGS'),
                ('FR_CTO', 'SUPPORTS_TRADES'),
                ('FR_CTO', 'SUPPORTS_FEES'),
                ('FR_CTO', 'SUPPORTS_TAX_TRACKING'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_BALANCE'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_TRANSACTIONS'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_HOLDINGS'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_ARBITRAGE'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_CONTRIBUTIONS'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_FEES'),
                ('FR_LIFE_INSURANCE', 'SUPPORTS_TAX_TRACKING')
            SQL);
    }
}

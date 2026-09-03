<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records a ceiling measured on the total balance a product holds, credited interest included.';
    }

    public function up(Schema $schema): void
    {
        // A deposit ceiling excludes the interest the institution credits, so a
        // passbook carried past its ceiling by its own interest has broken no
        // rule. A product capped on everything it holds gives the opposite
        // verdict on the same figure, and recording it as a deposit ceiling
        // would silently answer the wrong one. The kind exists so the measure
        // is stated by the rule rather than assumed by whoever reads it.
        $this->addSql('ALTER TABLE catalog_product_rules DROP CONSTRAINT catalog_product_rules_kind_valid');
        $this->addSql(<<<'SQL'
            ALTER TABLE catalog_product_rules ADD CONSTRAINT catalog_product_rules_kind_valid CHECK (rule_kind IN (
                'DEPOSIT_CEILING', 'BALANCE_CEILING', 'CONTRIBUTION_CEILING',
                'COMBINED_CONTRIBUTION_CEILING', 'ANNUAL_RATE', 'MIN_RATE',
                'INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE'))
            SQL);
        $this->addSql('ALTER TABLE catalog_product_rules DROP CONSTRAINT catalog_product_rules_value_matches_kind');
        $this->addSql(<<<'SQL'
            ALTER TABLE catalog_product_rules ADD CONSTRAINT catalog_product_rules_value_matches_kind CHECK (
                (rule_kind IN ('DEPOSIT_CEILING', 'BALANCE_CEILING', 'CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTION_CEILING')
                    AND amount_value IS NOT NULL)
                OR (rule_kind IN ('ANNUAL_RATE', 'MIN_RATE') AND percentage_value IS NOT NULL)
                OR (rule_kind IN ('INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')
                    AND text_value IS NOT NULL)
            )
            SQL);

        // Both maps below decide which capability a rule requires. A balance
        // ceiling is read on the balance, so it requires the same capability a
        // deposit ceiling does.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_assert_product_capabilities(target_code VARCHAR) RETURNS VOID AS $$
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
                    ('BALANCE_CEILING', 'SUPPORTS_BALANCE'),
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
            CREATE OR REPLACE FUNCTION catalog_product_rule_capability_guard() RETURNS TRIGGER AS $$
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
                    WHEN 'BALANCE_CEILING' THEN 'SUPPORTS_BALANCE'
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product_rules DROP CONSTRAINT catalog_product_rules_kind_valid');
        $this->addSql(<<<'SQL'
            ALTER TABLE catalog_product_rules ADD CONSTRAINT catalog_product_rules_kind_valid CHECK (rule_kind IN (
                'DEPOSIT_CEILING', 'CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTION_CEILING',
                'ANNUAL_RATE', 'MIN_RATE', 'INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE'))
            SQL);
        $this->addSql('ALTER TABLE catalog_product_rules DROP CONSTRAINT catalog_product_rules_value_matches_kind');
        $this->addSql(<<<'SQL'
            ALTER TABLE catalog_product_rules ADD CONSTRAINT catalog_product_rules_value_matches_kind CHECK (
                (rule_kind IN ('DEPOSIT_CEILING', 'CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTION_CEILING')
                    AND amount_value IS NOT NULL)
                OR (rule_kind IN ('ANNUAL_RATE', 'MIN_RATE') AND percentage_value IS NOT NULL)
                OR (rule_kind IN ('INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')
                    AND text_value IS NOT NULL)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_assert_product_capabilities(target_code VARCHAR) RETURNS VOID AS $$
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
            CREATE OR REPLACE FUNCTION catalog_product_rule_capability_guard() RETURNS TRIGGER AS $$
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
    }
}

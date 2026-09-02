<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the global read-only financial product catalogue with its dated, sourced rules.';
    }

    public function up(Schema $schema): void
    {
        // Lets the rule table exclude overlapping periods on a composite key:
        // btree_gist teaches GiST to index the equality columns alongside the
        // range. It is a trusted extension, so the database owner may install
        // it without superuser rights.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Like reference_assets, the catalogue is a global system reference: a
        // product belongs to no workspace, no request writes these tables, and
        // a corrected regulatory value arrives through a reviewed migration.
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_sources (
                id UUID NOT NULL,
                publisher VARCHAR(120) NOT NULL,
                title VARCHAR(200) NOT NULL,
                url VARCHAR(512) NOT NULL,
                published_on DATE,
                retrieved_on DATE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT catalog_product_sources_publisher_present CHECK (btrim(publisher) <> ''),
                CONSTRAINT catalog_product_sources_title_present CHECK (btrim(title) <> ''),
                -- An official reference is fetched over TLS or not at all: a
                -- plain http source could be rewritten in transit.
                CONSTRAINT catalog_product_sources_url_secure CHECK (url LIKE 'https://%'),
                CONSTRAINT catalog_product_sources_read_after_publication
                    CHECK (published_on IS NULL OR published_on <= retrieved_on)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_products (
                code VARCHAR(32) NOT NULL,
                display_name VARCHAR(80) NOT NULL,
                jurisdiction CHAR(2),
                account_kind VARCHAR(24) NOT NULL,
                wrapper_kind VARCHAR(24) NOT NULL,
                yield_kind VARCHAR(24) NOT NULL,
                default_group_code VARCHAR(32),
                catalog_version INTEGER NOT NULL,
                archived_at TIMESTAMP(0) WITH TIME ZONE,
                PRIMARY KEY (code),
                CONSTRAINT catalog_products_code_format CHECK (code ~ '^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$'),
                CONSTRAINT catalog_products_display_name_present CHECK (btrim(display_name) <> ''),
                -- A generic model belongs to no country; a French one names it.
                CONSTRAINT catalog_products_jurisdiction_format CHECK (jurisdiction IS NULL OR jurisdiction ~ '^[A-Z]{2}$'),
                CONSTRAINT catalog_products_group_code_format
                    CHECK (default_group_code IS NULL OR default_group_code ~ '^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$'),
                CONSTRAINT catalog_products_account_kind_valid CHECK (account_kind IN (
                    'CURRENT', 'SAVINGS', 'PORTFOLIO', 'INSURANCE_CONTRACT',
                    'EMPLOYEE_BENEFIT', 'CASH', 'REAL_ASSET', 'LIABILITY')),
                CONSTRAINT catalog_products_wrapper_kind_valid CHECK (wrapper_kind IN (
                    'NONE', 'REGULATED_SAVINGS', 'TAX_WRAPPER', 'SECURITIES_ACCOUNT',
                    'LIFE_INSURANCE', 'RETIREMENT', 'EMPLOYEE_SAVINGS')),
                CONSTRAINT catalog_products_yield_kind_valid CHECK (yield_kind IN (
                    'NONE', 'REGULATED_RATE', 'CONTRACTUAL_FIXED', 'CONTRACTUAL_VARIABLE',
                    'MARKET', 'MANUAL_VALUATION')),
                CONSTRAINT catalog_products_version_positive CHECK (catalog_version >= 1)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_product_rules (
                id UUID NOT NULL,
                product_code VARCHAR(32) NOT NULL,
                rule_kind VARCHAR(32) NOT NULL,
                amount_value NUMERIC(50, 24),
                amount_asset VARCHAR(12),
                percentage_value NUMERIC(50, 24),
                text_value VARCHAR(64),
                valid_from DATE NOT NULL,
                valid_to DATE,
                source_id UUID NOT NULL,
                verified_on DATE,
                verified_by VARCHAR(80),
                PRIMARY KEY (id),
                CONSTRAINT catalog_product_rules_product_exists
                    FOREIGN KEY (product_code) REFERENCES catalog_products (code),
                CONSTRAINT catalog_product_rules_source_exists
                    FOREIGN KEY (source_id) REFERENCES catalog_product_sources (id),
                -- An amount is denominated in an asset of the REF-001
                -- reference, so a ceiling can never be a bare number.
                CONSTRAINT catalog_product_rules_asset_exists
                    FOREIGN KEY (amount_asset) REFERENCES reference_assets (code),
                CONSTRAINT catalog_product_rules_kind_valid CHECK (rule_kind IN (
                    'DEPOSIT_CEILING', 'CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTION_CEILING',
                    'ANNUAL_RATE', 'MIN_RATE', 'INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')),
                CONSTRAINT catalog_product_rules_single_value
                    CHECK (num_nonnulls(amount_value, percentage_value, text_value) = 1),
                CONSTRAINT catalog_product_rules_amount_denominated
                    CHECK ((amount_value IS NULL) = (amount_asset IS NULL)),
                CONSTRAINT catalog_product_rules_ceiling_not_negative
                    CHECK (amount_value IS NULL OR amount_value >= 0),
                -- A percentage is stored as published: 1.7 reads 1.7 %, so no
                -- consumer converts a rate to display it.
                CONSTRAINT catalog_product_rules_percentage_bounded
                    CHECK (percentage_value IS NULL OR percentage_value BETWEEN -100 AND 100),
                -- Regulatory wording lives in the source and in the
                -- translation catalogue; a text rule stores only a token.
                CONSTRAINT catalog_product_rules_text_is_a_token
                    CHECK (text_value IS NULL OR text_value ~ '^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$'),
                CONSTRAINT catalog_product_rules_value_matches_kind CHECK (
                    (rule_kind IN ('DEPOSIT_CEILING', 'CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTION_CEILING')
                        AND amount_value IS NOT NULL)
                    OR (rule_kind IN ('ANNUAL_RATE', 'MIN_RATE') AND percentage_value IS NOT NULL)
                    OR (rule_kind IN ('INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')
                        AND text_value IS NOT NULL)
                ),
                CONSTRAINT catalog_product_rules_period_ordered
                    CHECK (valid_to IS NULL OR valid_to >= valid_from),
                -- Half a verification trace would show a date nobody stands
                -- behind, or a name with nothing to date it.
                CONSTRAINT catalog_product_rules_verification_whole
                    CHECK ((verified_on IS NULL) = (verified_by IS NULL)),
                -- The invariant an update must not be able to break: a
                -- catalogue revision appends a period, and two rules of one
                -- kind never cover the same day for one product.
                CONSTRAINT catalog_product_rules_periods_do_not_overlap EXCLUDE USING gist (
                    product_code WITH =,
                    rule_kind WITH =,
                    daterange(valid_from, valid_to, '[]') WITH &&
                )
            )
            SQL);

        $this->addSql('CREATE INDEX idx_catalog_product_rules_product ON catalog_product_rules (product_code, rule_kind, valid_from)');

        $this->seedSources();
        $this->seedProducts();
        $this->seedRules();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_product_rules');
        $this->addSql('DROP TABLE catalog_products');
        $this->addSql('DROP TABLE catalog_product_sources');
        // btree_gist is left installed: another table may already rely on it,
        // and dropping a shared extension is not this migration's to decide.
    }

    /**
     * The publications the seeded values were read from, with the day a
     * maintainer actually opened them. Every displayed regulatory figure
     * traces back to one of these rows.
     */
    private function seedSources(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO catalog_product_sources (id, publisher, title, url, published_on, retrieved_on) VALUES
                ('0199c0de-0001-7000-8000-000000000001',
                 'Direction de l''information légale et administrative',
                 'Livret A',
                 'https://www.service-public.fr/particuliers/vosdroits/F2365',
                 NULL, DATE '2026-08-22'),
                ('0199c0de-0001-7000-8000-000000000002',
                 'Direction de l''information légale et administrative',
                 'Livret A : réglementation du plafond de versement',
                 'https://www.service-public.fr/particuliers/actualites/A18241',
                 DATE '2025-04-25', DATE '2026-08-22'),
                ('0199c0de-0001-7000-8000-000000000003',
                 'Direction de l''information légale et administrative',
                 'Évolution des taux du Livret A et du LEP au 1er août 2026',
                 'https://www.service-public.fr/particuliers/actualites/A18000',
                 NULL, DATE '2026-08-22'),
                ('0199c0de-0001-7000-8000-000000000004',
                 'Direction de l''information légale et administrative',
                 'Livret de développement durable et solidaire (LDDS)',
                 'https://www.service-public.fr/particuliers/vosdroits/F2368',
                 NULL, DATE '2026-08-22'),
                ('0199c0de-0001-7000-8000-000000000005',
                 'Direction de l''information légale et administrative',
                 'Livret d''épargne populaire (LEP)',
                 'https://www.service-public.fr/particuliers/vosdroits/F2367',
                 NULL, DATE '2026-08-22'),
                ('0199c0de-0001-7000-8000-000000000006',
                 'Autorité des marchés financiers',
                 'PEA : tout savoir sur le plan d''épargne en actions',
                 'https://www.amf-france.org/fr/espace-epargnants/comprendre-les-produits-financiers/supports-dinvestissement/pea-tout-savoir-sur-le-plan-depargne-en-actions',
                 NULL, DATE '2026-08-22'),
                ('0199c0de-0001-7000-8000-000000000007',
                 'Autorité des marchés financiers',
                 'Le compte-titres ordinaire',
                 'https://www.amf-france.org/fr/espace-epargnants/comprendre-les-produits-financiers/supports-dinvestissement/compte-titres',
                 NULL, DATE '2026-08-22')
            SQL);
    }

    /**
     * The initial French product models. A market product declares a MARKET or
     * MANUAL_VALUATION yield, which is what forbids it a rate rule: a PEA, a
     * CTO or a life-insurance contract earns what its assets earn, and the
     * catalogue must not suggest otherwise.
     */
    private function seedProducts(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO catalog_products
                (code, display_name, jurisdiction, account_kind, wrapper_kind, yield_kind, default_group_code, catalog_version) VALUES
                ('FR_LIVRET_A', 'Livret A', 'FR', 'SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE', 'LIQUIDITY_SAVINGS', 1),
                ('FR_LDDS', 'Livret de développement durable et solidaire', 'FR', 'SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE', 'LIQUIDITY_SAVINGS', 1),
                ('FR_LEP', 'Livret d''épargne populaire', 'FR', 'SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE', 'LIQUIDITY_SAVINGS', 1),
                ('FR_LIVRET_JEUNE', 'Livret jeune', 'FR', 'SAVINGS', 'REGULATED_SAVINGS', 'REGULATED_RATE', 'LIQUIDITY_SAVINGS', 1),
                ('FR_PEA', 'Plan d''épargne en actions', 'FR', 'PORTFOLIO', 'TAX_WRAPPER', 'MARKET', 'INVESTMENTS_MARKET', 1),
                ('FR_PEA_PME', 'PEA-PME', 'FR', 'PORTFOLIO', 'TAX_WRAPPER', 'MARKET', 'INVESTMENTS_MARKET', 1),
                ('FR_CTO', 'Compte-titres ordinaire', 'FR', 'PORTFOLIO', 'SECURITIES_ACCOUNT', 'MARKET', 'INVESTMENTS_MARKET', 1),
                ('FR_LIFE_INSURANCE', 'Assurance-vie', 'FR', 'INSURANCE_CONTRACT', 'LIFE_INSURANCE', 'MANUAL_VALUATION', 'INVESTMENTS_LIFE_INSURANCE', 1)
            SQL);
    }

    /**
     * `valid_from` is the first day this catalogue can vouch for the value: the
     * effective date the source states, or failing that the day the source was
     * published, or failing that the day it was read. Earlier periods are
     * appended when a maintainer sources them; they are never back-filled with
     * a value nobody checked.
     *
     * Regulated French rates are set for a fixed semester — 1 February to
     * 31 July, 1 August to 31 January — so their periods carry both bounds.
     * The next revision then inserts a row instead of editing one, and the
     * history stays readable.
     *
     * Livret jeune and life insurance are seeded as products with no rule:
     * their regulatory values are not in this reference set yet, and an
     * unsourced figure must never reach a screen. The API reports the missing
     * rate as unavailable rather than as zero.
     */
    private function seedRules(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO catalog_product_rules
                (id, product_code, rule_kind, amount_value, amount_asset, percentage_value, text_value,
                 valid_from, valid_to, source_id, verified_on, verified_by) VALUES
                ('0199c0de-0002-7000-8000-000000000001', 'FR_LIVRET_A', 'DEPOSIT_CEILING',
                 22950.00, 'EUR', NULL, NULL, DATE '2025-04-25', NULL,
                 '0199c0de-0001-7000-8000-000000000002', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000002', 'FR_LIVRET_A', 'ANNUAL_RATE',
                 NULL, NULL, 1.7, NULL, DATE '2026-08-01', DATE '2027-01-31',
                 '0199c0de-0001-7000-8000-000000000003', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000003', 'FR_LDDS', 'DEPOSIT_CEILING',
                 12000.00, 'EUR', NULL, NULL, DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000004', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000004', 'FR_LDDS', 'ANNUAL_RATE',
                 NULL, NULL, 1.7, NULL, DATE '2026-08-01', DATE '2027-01-31',
                 '0199c0de-0001-7000-8000-000000000004', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000005', 'FR_LEP', 'DEPOSIT_CEILING',
                 10000.00, 'EUR', NULL, NULL, DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000005', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000006', 'FR_LEP', 'ANNUAL_RATE',
                 NULL, NULL, 2.5, NULL, DATE '2026-08-01', DATE '2027-01-31',
                 '0199c0de-0001-7000-8000-000000000003', DATE '2026-08-22', 'cadran-maintainer'),
                -- The PEA ceiling is on cumulative contributions, not on what
                -- the plan is worth: a plan may exceed it through market value.
                ('0199c0de-0002-7000-8000-000000000007', 'FR_PEA', 'CONTRIBUTION_CEILING',
                 150000.00, 'EUR', NULL, NULL, DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000006', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000008', 'FR_PEA', 'COMBINED_CONTRIBUTION_CEILING',
                 225000.00, 'EUR', NULL, NULL, DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000006', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-000000000009', 'FR_PEA_PME', 'CONTRIBUTION_CEILING',
                 225000.00, 'EUR', NULL, NULL, DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000006', DATE '2026-08-22', 'cadran-maintainer'),
                ('0199c0de-0002-7000-8000-00000000000a', 'FR_PEA_PME', 'COMBINED_CONTRIBUTION_CEILING',
                 225000.00, 'EUR', NULL, NULL, DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000006', DATE '2026-08-22', 'cadran-maintainer'),
                -- Absence of a ceiling is itself a sourced fact, and reads
                -- differently from a ceiling nobody has recorded yet.
                ('0199c0de-0002-7000-8000-00000000000b', 'FR_CTO', 'ELIGIBILITY',
                 NULL, NULL, NULL, 'NO_REGULATORY_CONTRIBUTION_CEILING', DATE '2026-08-22', NULL,
                 '0199c0de-0001-7000-8000-000000000007', DATE '2026-08-22', 'cadran-maintainer')
            SQL);
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Workspace-owned product models: what a workspace's own institution sells,
 * beside the system catalogue rather than inside it.
 *
 * The catalogue tables carry no `workspace_id` because they are a global,
 * sourced, read-only reference. These do, because a workspace writes them, and
 * every constraint the aggregate protects is repeated here: a migration, a
 * fixture or a future import reaching these tables gets the same refusals.
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates workspace-owned product models with dated rule periods and tiered rate scales.';
    }

    public function up(Schema $schema): void
    {
        // btree_gist lets an exclusion constraint compare a plain column and a
        // range side by side, which is what makes "two periods of one kind
        // never cover the same day" a database rule rather than a hope.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_product_models (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                name VARCHAR(80) NOT NULL,
                normalized_name TEXT GENERATED ALWAYS AS (lower(btrim(name))) STORED,
                family VARCHAR(24) NOT NULL,
                wrapper_kind VARCHAR(24) NOT NULL,
                yield_kind VARCHAR(24) NOT NULL,
                default_group_code VARCHAR(32) DEFAULT NULL,
                valuation_mode VARCHAR(16) NOT NULL,
                origin VARCHAR(16) NOT NULL,
                derived_from_product_code VARCHAR(32) DEFAULT NULL,
                derived_from_model_id UUID DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                -- The child tables reference the pair, so a rule period can
                -- never be filed under another workspace than its model.
                CONSTRAINT account_product_models_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT account_product_models_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                -- A model copied from the catalogue keeps the code it started
                -- from for reading. It stops following it: a later catalogue
                -- revision never changes what the workspace model says.
                CONSTRAINT account_product_models_source_product_fk FOREIGN KEY (derived_from_product_code)
                    REFERENCES catalog_products (code) ON DELETE RESTRICT,
                -- NO ACTION rather than RESTRICT: a copy still pins the model
                -- it started from, but one statement may remove a whole chain
                -- of them, which is what a workspace teardown does.
                CONSTRAINT account_product_models_source_model_fk FOREIGN KEY (derived_from_model_id)
                    REFERENCES account_product_models (id),
                CONSTRAINT account_product_models_name_present CHECK (btrim(name) = name AND name <> ''),
                CONSTRAINT account_product_models_name_bounded CHECK (char_length(name) <= 80),
                CONSTRAINT account_product_models_family_valid CHECK (family IN (
                    'CURRENT', 'SAVINGS', 'PORTFOLIO', 'INSURANCE_CONTRACT',
                    'EMPLOYEE_BENEFIT', 'CASH', 'REAL_ASSET', 'LIABILITY')),
                CONSTRAINT account_product_models_wrapper_kind_valid CHECK (wrapper_kind IN (
                    'NONE', 'REGULATED_SAVINGS', 'TAX_WRAPPER', 'SECURITIES_ACCOUNT',
                    'LIFE_INSURANCE', 'RETIREMENT', 'EMPLOYEE_SAVINGS')),
                CONSTRAINT account_product_models_yield_kind_valid CHECK (yield_kind IN (
                    'NONE', 'REGULATED_RATE', 'CONTRACTUAL_FIXED', 'CONTRACTUAL_VARIABLE',
                    'MARKET', 'MANUAL_VALUATION')),
                CONSTRAINT account_product_models_valuation_mode_valid
                    CHECK (valuation_mode IN ('TRANSACTIONS', 'SNAPSHOTS', 'PORTFOLIO')),
                CONSTRAINT account_product_models_portfolio_valuation_holds_positions CHECK (
                    valuation_mode <> 'PORTFOLIO'
                    OR family IN ('PORTFOLIO', 'INSURANCE_CONTRACT', 'EMPLOYEE_BENEFIT')
                ),
                CONSTRAINT account_product_models_group_code_valid CHECK (
                    default_group_code IS NULL
                    OR default_group_code ~ '^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$'
                ),
                CONSTRAINT account_product_models_origin_valid
                    CHECK (origin IN ('DECLARED', 'SYSTEM_PRODUCT', 'WORKSPACE_MODEL')),
                -- A model that claims a source must name it, and one that
                -- claims none must name nothing: half a provenance would show
                -- a copy as catalogue-backed with nothing behind it.
                CONSTRAINT account_product_models_provenance_complete CHECK (
                    (origin = 'DECLARED' AND derived_from_product_code IS NULL AND derived_from_model_id IS NULL)
                    OR (origin = 'SYSTEM_PRODUCT' AND derived_from_product_code IS NOT NULL AND derived_from_model_id IS NULL)
                    OR (origin = 'WORKSPACE_MODEL' AND derived_from_product_code IS NULL AND derived_from_model_id IS NOT NULL)
                ),
                CONSTRAINT account_product_models_not_its_own_source CHECK (derived_from_model_id <> id),
                CONSTRAINT account_product_models_version_valid CHECK (version > 0),
                CONSTRAINT account_product_models_updated_after_creation CHECK (updated_at >= created_at)
            )
            SQL);

        // Two live models sharing a name are indistinguishable in a picker or
        // in an account wizard. Archiving frees the name.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX account_product_models_active_name_unique
                ON account_product_models (workspace_id, normalized_name)
                WHERE archived_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX account_product_models_listing_index ON account_product_models (workspace_id, archived_at, normalized_name)');
        // PostgreSQL does not index a referencing foreign-key column on its
        // own; these keep the ON DELETE RESTRICT checks off a scan.
        $this->addSql('CREATE INDEX account_product_models_source_product_index ON account_product_models (derived_from_product_code)');
        $this->addSql('CREATE INDEX account_product_models_source_model_index ON account_product_models (derived_from_model_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_product_model_capabilities (
                model_id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                capability_code VARCHAR(32) NOT NULL,
                PRIMARY KEY (model_id, capability_code),
                CONSTRAINT account_product_model_capabilities_model_fk FOREIGN KEY (model_id, workspace_id)
                    REFERENCES account_product_models (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT account_product_model_capabilities_code_valid CHECK (capability_code IN (
                    'SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST',
                    'SUPPORTS_HOLDINGS', 'SUPPORTS_TRADES', 'SUPPORTS_ARBITRAGE',
                    'SUPPORTS_CONTRIBUTIONS', 'SUPPORTS_FEES', 'SUPPORTS_TAX_TRACKING',
                    'SUPPORTS_LIABILITY'))
            )
            SQL);
        $this->addSql('CREATE INDEX account_product_model_capabilities_workspace_index ON account_product_model_capabilities (workspace_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_product_model_rules (
                id UUID NOT NULL,
                model_id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                rule_kind VARCHAR(32) NOT NULL,
                amount_value NUMERIC(50, 24) DEFAULT NULL,
                amount_asset VARCHAR(12) DEFAULT NULL,
                text_value VARCHAR(64) DEFAULT NULL,
                rate_application VARCHAR(16) DEFAULT NULL,
                valid_from DATE NOT NULL,
                valid_to DATE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT account_product_model_rules_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT account_product_model_rules_model_fk FOREIGN KEY (model_id, workspace_id)
                    REFERENCES account_product_models (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT account_product_model_rules_asset_fk FOREIGN KEY (amount_asset)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT account_product_model_rules_kind_valid CHECK (rule_kind IN (
                    'DEPOSIT_CEILING', 'BALANCE_CEILING', 'CONTRIBUTION_CEILING',
                    'COMBINED_CONTRIBUTION_CEILING', 'ANNUAL_RATE', 'MIN_RATE',
                    'INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')),
                -- Each kind fixes the shape of its value, so a ceiling can
                -- never be recorded as free text nor a rate as an amount. A
                -- rate carries no figure here: it is the bracket scale below.
                CONSTRAINT account_product_model_rules_value_matches_kind CHECK (
                    (rule_kind IN ('DEPOSIT_CEILING', 'BALANCE_CEILING', 'CONTRIBUTION_CEILING', 'COMBINED_CONTRIBUTION_CEILING')
                        AND amount_value IS NOT NULL AND amount_asset IS NOT NULL
                        AND text_value IS NULL AND rate_application IS NULL)
                    OR (rule_kind IN ('ANNUAL_RATE', 'MIN_RATE')
                        AND rate_application IS NOT NULL
                        AND amount_value IS NULL AND amount_asset IS NULL AND text_value IS NULL)
                    OR (rule_kind IN ('INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')
                        AND text_value IS NOT NULL
                        AND amount_value IS NULL AND amount_asset IS NULL AND rate_application IS NULL)
                ),
                CONSTRAINT account_product_model_rules_amount_not_negative
                    CHECK (amount_value IS NULL OR amount_value >= 0),
                CONSTRAINT account_product_model_rules_text_valid CHECK (
                    text_value IS NULL OR text_value ~ '^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$'
                ),
                CONSTRAINT account_product_model_rules_rate_application_valid CHECK (
                    rate_application IS NULL OR rate_application IN ('MARGINAL', 'FLAT_BY_BRACKET')
                ),
                -- A null end means "in force with no known end", never an
                -- expiry, so only a stated end is compared to the start.
                CONSTRAINT account_product_model_rules_period_ordered
                    CHECK (valid_to IS NULL OR valid_to >= valid_from),
                CONSTRAINT account_product_model_rules_period_bounded
                    CHECK (valid_from >= DATE '1900-01-01' AND valid_from <= DATE '2100-12-31'
                        AND (valid_to IS NULL OR valid_to <= DATE '2100-12-31')),
                -- "The rate on 12 March" has one answer or none. A second
                -- period of the same kind covering that day is refused here,
                -- not reconciled by whichever query happens to read first.
                CONSTRAINT account_product_model_rules_no_overlap EXCLUDE USING gist (
                    model_id WITH =,
                    rule_kind WITH =,
                    daterange(valid_from, valid_to, '[]') WITH &&
                )
            )
            SQL);
        $this->addSql('CREATE INDEX account_product_model_rules_model_index ON account_product_model_rules (workspace_id, model_id, rule_kind, valid_from)');
        $this->addSql('CREATE INDEX account_product_model_rules_asset_index ON account_product_model_rules (amount_asset)');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_product_model_rate_brackets (
                rule_id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                position INTEGER NOT NULL,
                lower_bound NUMERIC(50, 24) NOT NULL,
                upper_bound NUMERIC(50, 24) DEFAULT NULL,
                percentage NUMERIC(50, 24) NOT NULL,
                PRIMARY KEY (rule_id, position),
                CONSTRAINT account_product_model_rate_brackets_rule_fk FOREIGN KEY (rule_id, workspace_id)
                    REFERENCES account_product_model_rules (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT account_product_model_rate_brackets_position_valid CHECK (position >= 1),
                CONSTRAINT account_product_model_rate_brackets_lower_bound_valid CHECK (lower_bound >= 0),
                -- The lower bound is included and the upper excluded, so an
                -- empty slice would be a bracket covering nothing at all.
                CONSTRAINT account_product_model_rate_brackets_ordered
                    CHECK (upper_bound IS NULL OR upper_bound > lower_bound)
            )
            SQL);
        $this->addSql('CREATE INDEX account_product_model_rate_brackets_workspace_index ON account_product_model_rate_brackets (workspace_id)');

        // A scale with a gap, an overlap, no bracket at zero or no open upper
        // bracket leaves amounts with no rate, or two. Read as a guaranteed
        // yield, either would be a promise nothing backs, so the tiling is
        // asserted at commit time rather than left to whoever writes next.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_assert_model_rate_scale(target_rule UUID) RETURNS VOID AS $$
            DECLARE
                target_kind VARCHAR;
                bracket_count INTEGER;
                previous_upper NUMERIC(50, 24);
                bracket RECORD;
                expected_position INTEGER := 0;
            BEGIN
                SELECT rule_kind INTO target_kind
                FROM account_product_model_rules
                WHERE id = target_rule;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT count(*) INTO bracket_count
                FROM account_product_model_rate_brackets
                WHERE rule_id = target_rule;

                IF target_kind NOT IN ('ANNUAL_RATE', 'MIN_RATE') THEN
                    IF bracket_count > 0 THEN
                        RAISE EXCEPTION 'Rule % states no rate and carries no bracket.', target_rule
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN;
                END IF;

                IF bracket_count = 0 THEN
                    RAISE EXCEPTION 'Rate rule % carries at least one bracket.', target_rule
                        USING ERRCODE = 'check_violation';
                END IF;

                FOR bracket IN
                    SELECT position, lower_bound, upper_bound
                    FROM account_product_model_rate_brackets
                    WHERE rule_id = target_rule
                    ORDER BY position
                LOOP
                    expected_position := expected_position + 1;

                    IF bracket.position <> expected_position THEN
                        RAISE EXCEPTION 'Rate rule % numbers its brackets from one without a gap.', target_rule
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF expected_position = 1 THEN
                        IF bracket.lower_bound <> 0 THEN
                            RAISE EXCEPTION 'Rate rule % starts at zero.', target_rule
                                USING ERRCODE = 'check_violation';
                        END IF;
                    ELSIF previous_upper IS NULL OR bracket.lower_bound <> previous_upper THEN
                        RAISE EXCEPTION 'Each bracket of rate rule % starts where the previous one ends.', target_rule
                            USING ERRCODE = 'check_violation';
                    END IF;

                    previous_upper := bracket.upper_bound;
                END LOOP;

                IF previous_upper IS NOT NULL THEN
                    RAISE EXCEPTION 'The last bracket of rate rule % runs without an upper limit.', target_rule
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_model_rate_scale_trigger() RETURNS TRIGGER AS $$
            BEGIN
                IF TG_TABLE_NAME = 'account_product_model_rules' THEN
                    PERFORM account_assert_model_rate_scale(COALESCE(NEW.id, OLD.id));
                ELSE
                    PERFORM account_assert_model_rate_scale(COALESCE(NEW.rule_id, OLD.rule_id));
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // Deferred to commit: a rule and its brackets arrive as several
        // statements, and a scale is only complete once the whole business
        // operation is.
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER account_product_model_rules_scale_complete
                AFTER INSERT OR UPDATE ON account_product_model_rules
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION account_model_rate_scale_trigger()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER account_product_model_rate_brackets_scale_complete
                AFTER INSERT OR UPDATE OR DELETE ON account_product_model_rate_brackets
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION account_model_rate_scale_trigger()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_product_model_rate_brackets');
        $this->addSql('DROP TABLE account_product_model_rules');
        $this->addSql('DROP TABLE account_product_model_capabilities');
        $this->addSql('DROP TABLE account_product_models');
        $this->addSql('DROP FUNCTION IF EXISTS account_model_rate_scale_trigger()');
        $this->addSql('DROP FUNCTION IF EXISTS account_assert_model_rate_scale(UUID)');
    }
}

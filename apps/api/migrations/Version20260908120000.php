<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records dated rule overrides against a single account, in front of the catalogue or workspace model it follows.';
    }

    public function up(Schema $schema): void
    {
        // The account reference is carried as (id, workspace_id) so an override
        // of one workspace can never be attached to an account of another, and
        // no writer outside the use case can create that row either.
        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD CONSTRAINT account_financial_accounts_scoped_identity UNIQUE (id, workspace_id)
            SQL);

        // btree_gist lets an exclusion constraint compare a plain column and a
        // range in the same index. It is created if missing rather than
        // assumed: the migration that first needed it may have been squashed.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_rule_overrides (
                id UUID NOT NULL,
                account_id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                rule_kind VARCHAR(32) NOT NULL,
                amount_value NUMERIC(50, 24) DEFAULT NULL,
                amount_asset VARCHAR(12) DEFAULT NULL,
                text_value VARCHAR(64) DEFAULT NULL,
                rate_application VARCHAR(16) DEFAULT NULL,
                valid_from DATE NOT NULL,
                valid_to DATE DEFAULT NULL,
                -- Why this account diverges from what it inherits. It is
                -- required: an unexplained local figure sitting beside a
                -- published one is the silent regulatory drift this table has
                -- to make visible instead of hiding.
                reason VARCHAR(200) NOT NULL,
                author_id UUID NOT NULL,
                recorded_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                -- Withdrawing keeps the row and stops it applying on every
                -- date, past ones included. Ending an override is a different
                -- fact and lives in valid_to.
                withdrawn_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                withdrawn_by UUID DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT account_rule_overrides_scoped_identity UNIQUE (id, workspace_id),
                CONSTRAINT account_rule_overrides_account_fk FOREIGN KEY (account_id, workspace_id)
                    REFERENCES account_financial_accounts (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT account_rule_overrides_asset_fk FOREIGN KEY (amount_asset)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT account_rule_overrides_author_fk FOREIGN KEY (author_id)
                    REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT account_rule_overrides_withdrawer_fk FOREIGN KEY (withdrawn_by)
                    REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT account_rule_overrides_kind_valid CHECK (rule_kind IN (
                    'DEPOSIT_CEILING', 'BALANCE_CEILING', 'CONTRIBUTION_CEILING',
                    'COMBINED_CONTRIBUTION_CEILING', 'ANNUAL_RATE', 'MIN_RATE',
                    'INTEREST_ACCRUAL_METHOD', 'ELIGIBILITY', 'TAX_REFERENCE')),
                -- Each kind fixes the shape of its value, so a ceiling can
                -- never be recorded as free text nor a rate as an amount. A
                -- rate carries no figure here: it is the bracket scale below.
                CONSTRAINT account_rule_overrides_value_matches_kind CHECK (
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
                CONSTRAINT account_rule_overrides_amount_not_negative
                    CHECK (amount_value IS NULL OR amount_value >= 0),
                CONSTRAINT account_rule_overrides_text_valid CHECK (
                    text_value IS NULL OR text_value ~ '^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$'
                ),
                CONSTRAINT account_rule_overrides_rate_application_valid CHECK (
                    rate_application IS NULL OR rate_application IN ('MARGINAL', 'FLAT_BY_BRACKET')
                ),
                CONSTRAINT account_rule_overrides_reason_present
                    CHECK (btrim(reason) = reason AND reason <> ''),
                -- A null end means "in force with no known end", never an
                -- expiry, so only a stated end is compared to the start.
                CONSTRAINT account_rule_overrides_period_ordered
                    CHECK (valid_to IS NULL OR valid_to >= valid_from),
                CONSTRAINT account_rule_overrides_period_bounded
                    CHECK (valid_from >= DATE '1900-01-01' AND valid_from <= DATE '2100-12-31'
                        AND (valid_to IS NULL OR valid_to <= DATE '2100-12-31')),
                -- A withdrawal names who performed it, or it is not one.
                CONSTRAINT account_rule_overrides_withdrawal_attributed
                    CHECK ((withdrawn_at IS NULL) = (withdrawn_by IS NULL)),
                CONSTRAINT account_rule_overrides_withdrawn_after_recorded
                    CHECK (withdrawn_at IS NULL OR withdrawn_at >= recorded_at),
                -- "The ceiling this account claims on 12 March" has one answer
                -- or none. A second standing override of the same kind covering
                -- that day is refused here, not reconciled by whichever query
                -- happens to read first. A withdrawn one constrains nothing:
                -- withdrawing is exactly the act of freeing those dates.
                CONSTRAINT account_rule_overrides_no_overlap EXCLUDE USING gist (
                    account_id WITH =,
                    rule_kind WITH =,
                    daterange(valid_from, valid_to, '[]') WITH &&
                ) WHERE (withdrawn_at IS NULL)
            )
            SQL);
        $this->addSql('CREATE INDEX account_rule_overrides_account_index ON account_rule_overrides (workspace_id, account_id, rule_kind, valid_from)');
        $this->addSql('CREATE INDEX account_rule_overrides_asset_index ON account_rule_overrides (amount_asset)');
        $this->addSql('CREATE INDEX account_rule_overrides_author_index ON account_rule_overrides (author_id)');
        $this->addSql('CREATE INDEX account_rule_overrides_withdrawer_index ON account_rule_overrides (withdrawn_by)');

        $this->addSql(<<<'SQL'
            CREATE TABLE account_rule_override_brackets (
                override_id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                position INTEGER NOT NULL,
                lower_bound NUMERIC(50, 24) NOT NULL,
                upper_bound NUMERIC(50, 24) DEFAULT NULL,
                percentage NUMERIC(50, 24) NOT NULL,
                PRIMARY KEY (override_id, position),
                CONSTRAINT account_rule_override_brackets_override_fk FOREIGN KEY (override_id, workspace_id)
                    REFERENCES account_rule_overrides (id, workspace_id) ON DELETE CASCADE,
                CONSTRAINT account_rule_override_brackets_position_valid CHECK (position >= 1),
                CONSTRAINT account_rule_override_brackets_lower_bound_valid CHECK (lower_bound >= 0),
                -- The lower bound is included and the upper excluded, so an
                -- empty slice would be a bracket covering nothing at all.
                CONSTRAINT account_rule_override_brackets_ordered
                    CHECK (upper_bound IS NULL OR upper_bound > lower_bound)
            )
            SQL);
        $this->addSql('CREATE INDEX account_rule_override_brackets_workspace_index ON account_rule_override_brackets (workspace_id)');

        // A scale with a gap, an overlap, no bracket at zero or no open upper
        // bracket leaves amounts with no rate, or two. A locally claimed rate
        // is displayed beside a published one, so an incomplete one would be
        // read with the credibility of the figure it replaced; the tiling is
        // asserted at commit time rather than left to whoever writes next.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_assert_override_rate_scale(target_override UUID) RETURNS VOID AS $$
            DECLARE
                target_kind VARCHAR;
                bracket_count INTEGER;
                previous_upper NUMERIC(50, 24);
                bracket RECORD;
                expected_position INTEGER := 0;
            BEGIN
                SELECT rule_kind INTO target_kind
                FROM account_rule_overrides
                WHERE id = target_override;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT count(*) INTO bracket_count
                FROM account_rule_override_brackets
                WHERE override_id = target_override;

                IF target_kind NOT IN ('ANNUAL_RATE', 'MIN_RATE') THEN
                    IF bracket_count > 0 THEN
                        RAISE EXCEPTION 'Override % states no rate and carries no bracket.', target_override
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN;
                END IF;

                IF bracket_count = 0 THEN
                    RAISE EXCEPTION 'Rate override % carries at least one bracket.', target_override
                        USING ERRCODE = 'check_violation';
                END IF;

                FOR bracket IN
                    SELECT position, lower_bound, upper_bound
                    FROM account_rule_override_brackets
                    WHERE override_id = target_override
                    ORDER BY position
                LOOP
                    expected_position := expected_position + 1;

                    IF bracket.position <> expected_position THEN
                        RAISE EXCEPTION 'Rate override % numbers its brackets from one without a gap.', target_override
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF expected_position = 1 THEN
                        IF bracket.lower_bound <> 0 THEN
                            RAISE EXCEPTION 'Rate override % starts at zero.', target_override
                                USING ERRCODE = 'check_violation';
                        END IF;
                    ELSIF previous_upper IS NULL OR bracket.lower_bound <> previous_upper THEN
                        RAISE EXCEPTION 'Each bracket of rate override % starts where the previous one ends.', target_override
                            USING ERRCODE = 'check_violation';
                    END IF;

                    previous_upper := bracket.upper_bound;
                END LOOP;

                IF previous_upper IS NOT NULL THEN
                    RAISE EXCEPTION 'The last bracket of rate override % runs without an upper limit.', target_override
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_override_rate_scale_trigger() RETURNS TRIGGER AS $$
            BEGIN
                IF TG_TABLE_NAME = 'account_rule_overrides' THEN
                    PERFORM account_assert_override_rate_scale(COALESCE(NEW.id, OLD.id));
                ELSE
                    PERFORM account_assert_override_rate_scale(COALESCE(NEW.override_id, OLD.override_id));
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // Deferred to commit: an override and its brackets arrive as several
        // statements, and a scale is only complete once the whole business
        // operation is.
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER account_rule_overrides_scale_complete
                AFTER INSERT OR UPDATE ON account_rule_overrides
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION account_override_rate_scale_trigger()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER account_rule_override_brackets_scale_complete
                AFTER INSERT OR UPDATE OR DELETE ON account_rule_override_brackets
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION account_override_rate_scale_trigger()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_rule_override_brackets');
        $this->addSql('DROP TABLE account_rule_overrides');
        $this->addSql('DROP FUNCTION IF EXISTS account_override_rate_scale_trigger()');
        $this->addSql('DROP FUNCTION IF EXISTS account_assert_override_rate_scale(UUID)');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_scoped_identity');
    }
}

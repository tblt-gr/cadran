<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates exact workspace-scoped transactions and category splits with deferred exact-sum enforcement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_financial_accounts ADD CONSTRAINT account_financial_accounts_workspace_id_unique UNIQUE (workspace_id, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_transactions (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                account_id UUID NOT NULL,
                asset_code VARCHAR(12) NOT NULL,
                amount_value NUMERIC(50,24) NOT NULL,
                amount_scale SMALLINT NOT NULL,
                original_amount_value NUMERIC(50,24) DEFAULT NULL,
                original_amount_scale SMALLINT DEFAULT NULL,
                original_asset_code VARCHAR(12) DEFAULT NULL,
                exchange_rate NUMERIC(50,24) DEFAULT NULL,
                state VARCHAR(8) NOT NULL,
                nature VARCHAR(10) NOT NULL,
                source VARCHAR(8) NOT NULL DEFAULT 'MANUAL',
                source_ref VARCHAR(128) DEFAULT NULL,
                booked_on DATE NOT NULL,
                value_on DATE DEFAULT NULL,
                authorized_on DATE DEFAULT NULL,
                raw_label VARCHAR(140) NOT NULL,
                normalized_label TEXT GENERATED ALWAYS AS (lower(btrim(raw_label))) STORED,
                counterparty VARCHAR(80) DEFAULT NULL,
                note VARCHAR(500) DEFAULT NULL,
                payment_method VARCHAR(16) DEFAULT NULL,
                mcc CHAR(4) DEFAULT NULL,
                masked_card CHAR(4) DEFAULT NULL,
                bank_reference VARCHAR(64) DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                voided_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                last_editor_id UUID DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_transactions_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transactions_account_fk FOREIGN KEY (workspace_id, account_id)
                    REFERENCES account_financial_accounts (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transactions_asset_fk FOREIGN KEY (asset_code)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT transaction_transactions_original_asset_fk FOREIGN KEY (original_asset_code)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT transaction_transactions_last_editor_fk FOREIGN KEY (last_editor_id)
                    REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transactions_workspace_id_unique UNIQUE (workspace_id, id),
                CONSTRAINT transaction_transactions_state_valid CHECK (state IN ('PENDING', 'BOOKED', 'VOIDED', 'REJECTED')),
                CONSTRAINT transaction_transactions_nature_valid CHECK (nature IN ('INCOME', 'EXPENSE', 'TRANSFER', 'REFUND', 'FEE', 'ADJUSTMENT')),
                CONSTRAINT transaction_transactions_source_valid CHECK (source IN ('MANUAL', 'IMPORT', 'PROVIDER')),
                CONSTRAINT transaction_transactions_payment_method_valid CHECK (
                    payment_method IS NULL OR payment_method IN ('CARD', 'TRANSFER', 'DIRECT_DEBIT', 'CHECK', 'CASH', 'OTHER')
                ),
                CONSTRAINT transaction_transactions_amount_not_zero CHECK (amount_value <> 0),
                CONSTRAINT transaction_transactions_sign_matches_nature CHECK (
                    (nature = 'INCOME' AND amount_value > 0)
                    OR (nature IN ('EXPENSE', 'FEE') AND amount_value < 0)
                    OR nature IN ('TRANSFER', 'REFUND', 'ADJUSTMENT')
                ),
                CONSTRAINT transaction_transactions_amount_scale_valid CHECK (amount_scale BETWEEN 0 AND 24),
                CONSTRAINT transaction_transactions_original_amount_complete CHECK (
                    num_nulls(original_amount_value, original_amount_scale, original_asset_code, exchange_rate) IN (0, 4)
                ),
                CONSTRAINT transaction_transactions_original_amount_scale_valid CHECK (
                    original_amount_scale IS NULL OR original_amount_scale BETWEEN 0 AND 24
                ),
                CONSTRAINT transaction_transactions_original_asset_differs CHECK (
                    original_asset_code IS NULL OR original_asset_code <> asset_code
                ),
                CONSTRAINT transaction_transactions_exchange_rate_positive CHECK (exchange_rate IS NULL OR exchange_rate > 0),
                CONSTRAINT transaction_transactions_raw_label_present CHECK (btrim(raw_label) = raw_label AND raw_label <> ''),
                CONSTRAINT transaction_transactions_raw_label_bounded CHECK (char_length(raw_label) <= 140),
                CONSTRAINT transaction_transactions_counterparty_present CHECK (
                    counterparty IS NULL OR (btrim(counterparty) = counterparty AND counterparty <> '')
                ),
                CONSTRAINT transaction_transactions_counterparty_bounded CHECK (counterparty IS NULL OR char_length(counterparty) <= 80),
                CONSTRAINT transaction_transactions_note_present CHECK (note IS NULL OR (btrim(note) = note AND note <> '')),
                CONSTRAINT transaction_transactions_note_bounded CHECK (note IS NULL OR char_length(note) <= 500),
                CONSTRAINT transaction_transactions_bank_reference_present CHECK (
                    bank_reference IS NULL OR (btrim(bank_reference) = bank_reference AND bank_reference <> '')
                ),
                CONSTRAINT transaction_transactions_bank_reference_bounded CHECK (
                    bank_reference IS NULL OR char_length(bank_reference) <= 64
                ),
                CONSTRAINT transaction_transactions_mcc_valid CHECK (mcc IS NULL OR mcc ~ '^[0-9]{4}$'),
                CONSTRAINT transaction_transactions_masked_card_valid CHECK (masked_card IS NULL OR masked_card ~ '^[0-9]{4}$'),
                CONSTRAINT transaction_transactions_voided_state CHECK ((state = 'VOIDED') = (voided_at IS NOT NULL)),
                CONSTRAINT transaction_transactions_dates_ordered CHECK (authorized_on IS NULL OR authorized_on <= booked_on),
                CONSTRAINT transaction_transactions_value_date_bounded CHECK (
                    value_on IS NULL OR value_on BETWEEN booked_on - 90 AND booked_on + 90
                ),
                CONSTRAINT transaction_transactions_version_valid CHECK (version > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX transaction_transactions_listing_index ON transaction_transactions (workspace_id, booked_on DESC, id DESC)');
        $this->addSql('CREATE INDEX transaction_transactions_account_index ON transaction_transactions (workspace_id, account_id, booked_on DESC)');

        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_splits (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                transaction_id UUID NOT NULL,
                category_id UUID NOT NULL,
                amount_value NUMERIC(50,24) NOT NULL,
                amount_scale SMALLINT NOT NULL,
                asset_code VARCHAR(12) NOT NULL,
                note VARCHAR(140) DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_splits_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_splits_transaction_fk FOREIGN KEY (workspace_id, transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE CASCADE,
                CONSTRAINT transaction_splits_category_fk FOREIGN KEY (workspace_id, category_id)
                    REFERENCES category_categories (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_splits_asset_fk FOREIGN KEY (asset_code)
                    REFERENCES reference_assets (code) ON DELETE RESTRICT,
                CONSTRAINT transaction_splits_amount_not_zero CHECK (amount_value <> 0),
                CONSTRAINT transaction_splits_amount_scale_valid CHECK (amount_scale BETWEEN 0 AND 24),
                CONSTRAINT transaction_splits_note_present CHECK (note IS NULL OR (btrim(note) = note AND note <> '')),
                CONSTRAINT transaction_splits_note_bounded CHECK (note IS NULL OR char_length(note) <= 140)
            )
            SQL);
        $this->addSql('CREATE INDEX transaction_splits_transaction_index ON transaction_splits (workspace_id, transaction_id)');
        $this->addSql('CREATE INDEX transaction_splits_category_index ON transaction_splits (workspace_id, category_id)');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION transaction_splits_sum_matches_amount() RETURNS TRIGGER AS $$
            DECLARE
                target UUID := coalesce(NEW.transaction_id, OLD.transaction_id);
                target_workspace UUID := coalesce(NEW.workspace_id, OLD.workspace_id);
                expected NUMERIC(50,24);
                allocated NUMERIC(50,24);
                allocation_count INTEGER;
            BEGIN
                SELECT amount_value INTO expected
                FROM transaction_transactions
                WHERE workspace_id = target_workspace AND id = target;
                IF expected IS NULL THEN
                    RETURN NULL;
                END IF;
                SELECT coalesce(sum(amount_value), 0), count(*) INTO allocated, allocation_count
                FROM transaction_splits
                WHERE workspace_id = target_workspace AND transaction_id = target;
                IF allocation_count > 0 AND allocated <> expected THEN
                    RAISE EXCEPTION 'transaction splits do not sum to their amount';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER transaction_splits_sum_check
                AFTER INSERT OR UPDATE OR DELETE ON transaction_splits
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION transaction_splits_sum_matches_amount()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION transaction_amount_matches_splits() RETURNS TRIGGER AS $$
            DECLARE
                allocated NUMERIC(50,24);
                allocation_count INTEGER;
            BEGIN
                SELECT coalesce(sum(amount_value), 0), count(*) INTO allocated, allocation_count
                FROM transaction_splits
                WHERE workspace_id = NEW.workspace_id AND transaction_id = NEW.id;
                IF allocation_count > 0 AND allocated <> NEW.amount_value THEN
                    RAISE EXCEPTION 'transaction splits do not sum to their amount';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER transaction_amount_sum_check
                AFTER UPDATE OF amount_value ON transaction_transactions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION transaction_amount_matches_splits()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transaction_splits');
        $this->addSql('DROP FUNCTION transaction_splits_sum_matches_amount()');
        $this->addSql('DROP TABLE transaction_transactions');
        $this->addSql('DROP FUNCTION transaction_amount_matches_splits()');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_workspace_id_unique');
    }
}

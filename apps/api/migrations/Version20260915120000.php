<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates atomic internal transfers linking two transaction legs and an optional fee.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_transfers (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                source_transaction_id UUID NOT NULL,
                target_transaction_id UUID NOT NULL,
                fee_transaction_id UUID DEFAULT NULL,
                exchange_rate NUMERIC(50,24) DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                voided_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_transfers_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transfers_source_fk FOREIGN KEY (workspace_id, source_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transfers_target_fk FOREIGN KEY (workspace_id, target_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transfers_fee_fk FOREIGN KEY (workspace_id, fee_transaction_id)
                    REFERENCES transaction_transactions (workspace_id, id) ON DELETE RESTRICT,
                CONSTRAINT transaction_transfers_legs_differ
                    CHECK (source_transaction_id <> target_transaction_id),
                CONSTRAINT transaction_transfers_source_unique UNIQUE (source_transaction_id),
                CONSTRAINT transaction_transfers_target_unique UNIQUE (target_transaction_id),
                CONSTRAINT transaction_transfers_fee_unique UNIQUE (fee_transaction_id),
                CONSTRAINT transaction_transfers_rate_positive
                    CHECK (exchange_rate IS NULL OR exchange_rate > 0),
                CONSTRAINT transaction_transfers_version_valid CHECK (version > 0),
                CONSTRAINT transaction_transfers_voided_state CHECK ((voided_at IS NULL) OR (voided_at >= created_at))
            )
            SQL);
        $this->addSql('CREATE INDEX transaction_transfers_workspace_index ON transaction_transfers (workspace_id, id)');

        // A transfer leg carries no split: a transfer moves assets and is
        // never a categorised expense or income.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION transaction_splits_refuse_transfer_leg() RETURNS TRIGGER AS $$
            DECLARE
                leg_nature VARCHAR(10);
            BEGIN
                SELECT nature INTO leg_nature
                FROM transaction_transactions
                WHERE workspace_id = NEW.workspace_id AND id = NEW.transaction_id;
                IF leg_nature = 'TRANSFER' THEN
                    RAISE EXCEPTION 'a transfer leg cannot carry a split';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER transaction_splits_refuse_transfer_leg_check
                BEFORE INSERT ON transaction_splits
                FOR EACH ROW EXECUTE FUNCTION transaction_splits_refuse_transfer_leg()
            SQL);

        // The domain checks exact opposition before writing a same-asset pair;
        // this constraint trigger repeats it at the database boundary so a leg
        // edited outside the application, or a bypassed use case, cannot leave
        // the pair unbalanced. Deferred to the end of the transaction, so the
        // insert order of the transfer row and its two legs never matters.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION transaction_transfers_legs_net_to_zero() RETURNS TRIGGER AS $$
            DECLARE
                target UUID := coalesce(NEW.id, OLD.id);
                target_workspace UUID := coalesce(NEW.workspace_id, OLD.workspace_id);
                source_asset VARCHAR(12);
                source_amount NUMERIC(50,24);
                target_asset VARCHAR(12);
                target_amount NUMERIC(50,24);
            BEGIN
                SELECT t.asset_code, t.amount_value INTO source_asset, source_amount
                FROM transaction_transfers f
                JOIN transaction_transactions t ON t.workspace_id = f.workspace_id AND t.id = f.source_transaction_id
                WHERE f.workspace_id = target_workspace AND f.id = target;
                SELECT t.asset_code, t.amount_value INTO target_asset, target_amount
                FROM transaction_transfers f
                JOIN transaction_transactions t ON t.workspace_id = f.workspace_id AND t.id = f.target_transaction_id
                WHERE f.workspace_id = target_workspace AND f.id = target;
                IF source_asset = target_asset AND source_amount + target_amount <> 0 THEN
                    RAISE EXCEPTION 'a same-asset transfer must net to exactly zero';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER transaction_transfers_net_zero_check
                AFTER INSERT OR UPDATE ON transaction_transfers
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION transaction_transfers_legs_net_to_zero()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION transaction_amount_matches_transfer_pair() RETURNS TRIGGER AS $$
            DECLARE
                pair RECORD;
                other_asset VARCHAR(12);
                other_amount NUMERIC(50,24);
            BEGIN
                SELECT f.id, f.workspace_id, f.source_transaction_id, f.target_transaction_id INTO pair
                FROM transaction_transfers f
                WHERE f.workspace_id = NEW.workspace_id
                    AND (f.source_transaction_id = NEW.id OR f.target_transaction_id = NEW.id);
                IF pair.id IS NULL THEN
                    RETURN NULL;
                END IF;
                SELECT t.asset_code, t.amount_value INTO other_asset, other_amount
                FROM transaction_transactions t
                WHERE t.workspace_id = pair.workspace_id
                    AND t.id = (CASE WHEN pair.source_transaction_id = NEW.id THEN pair.target_transaction_id ELSE pair.source_transaction_id END);
                IF other_asset = NEW.asset_code AND other_amount + NEW.amount_value <> 0 THEN
                    RAISE EXCEPTION 'a same-asset transfer must net to exactly zero';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER transaction_amount_transfer_pair_check
                AFTER UPDATE OF amount_value ON transaction_transactions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION transaction_amount_matches_transfer_pair()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER transaction_amount_transfer_pair_check ON transaction_transactions');
        $this->addSql('DROP FUNCTION transaction_amount_matches_transfer_pair()');
        $this->addSql('DROP TRIGGER transaction_transfers_net_zero_check ON transaction_transfers');
        $this->addSql('DROP FUNCTION transaction_transfers_legs_net_to_zero()');
        $this->addSql('DROP TRIGGER transaction_splits_refuse_transfer_leg_check ON transaction_splits');
        $this->addSql('DROP FUNCTION transaction_splits_refuse_transfer_leg()');
        $this->addSql('DROP TABLE transaction_transfers');
    }
}

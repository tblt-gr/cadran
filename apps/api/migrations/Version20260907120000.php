<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Links a financial account to a reusable workspace product model, as an alternative to the system catalogue.';
    }

    public function up(Schema $schema): void
    {
        // Only the model reference is stored. A ceiling, a rate or an accrual
        // method stays on the model and is read on the business date it is
        // needed, exactly as a catalogue-backed account already reads its
        // product — so archiving the model never changes what an account
        // already created from it resolves.
        $this->addSql('ALTER TABLE account_financial_accounts ADD COLUMN product_model_id UUID DEFAULT NULL');

        // The reference is carried as (id, workspace_id, family) rather than
        // the identifier alone, the way the product reference is already
        // carried as (code, kind). A model of another workspace can therefore
        // never back an account here, a savings model cannot be filed as a
        // current account, and no writer outside the use case can create
        // that row either.
        $this->addSql(<<<'SQL'
            ALTER TABLE account_product_models
                ADD CONSTRAINT account_product_models_id_workspace_family_unique UNIQUE (id, workspace_id, family)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD CONSTRAINT account_financial_accounts_product_model_fk FOREIGN KEY (product_model_id, workspace_id, kind)
                    REFERENCES account_product_models (id, workspace_id, family) ON DELETE RESTRICT
            SQL);

        // An account names at most one origin. Two references at once would
        // leave a reader to guess which one the account actually resolves
        // against — the aggregate refuses the combination too, and this
        // repeats the refusal for any writer that reaches the table directly.
        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD CONSTRAINT account_financial_accounts_single_origin CHECK (
                    product_code IS NULL OR product_model_id IS NULL
                )
            SQL);

        // PostgreSQL does not index a referencing foreign-key column on its
        // own; this keeps the ON DELETE RESTRICT check on account_product_models
        // off a scan.
        $this->addSql('CREATE INDEX account_financial_accounts_product_model_index ON account_financial_accounts (product_model_id, workspace_id, kind)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX account_financial_accounts_product_model_index');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_single_origin');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_product_model_fk');
        $this->addSql('ALTER TABLE account_product_models DROP CONSTRAINT account_product_models_id_workspace_family_unique');
        $this->addSql('ALTER TABLE account_financial_accounts DROP COLUMN product_model_id');
    }
}

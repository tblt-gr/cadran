<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Links a financial account to the system product catalogue and records its holding institution.';
    }

    public function up(Schema $schema): void
    {
        // Only the catalogue reference is stored. A ceiling, a rate or an
        // accrual method stays in the catalogue and is read on the business date
        // it is needed, so a regulatory revision reaches every account at once
        // rather than leaving a copied constant behind in this table.
        $this->addSql('ALTER TABLE account_financial_accounts ADD COLUMN product_code VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE account_financial_accounts ADD COLUMN institution VARCHAR(80) DEFAULT NULL');

        // The reference is carried as (code, kind) rather than as the code
        // alone, the way catalog_product_rules already keys on (code,
        // yield_kind). A product-backed account therefore cannot be filed under
        // a kind its product does not declare — a PEA recorded as a passbook
        // would later be checked against a deposit ceiling instead of its
        // cumulative contributions — and no writer outside the use case can
        // create that row.
        $this->addSql('ALTER TABLE catalog_products ADD CONSTRAINT catalog_products_code_kind_unique UNIQUE (code, account_kind)');

        // The catalogue is a global system reference with no workspace column,
        // so a code either exists for every workspace or for none. An unknown
        // reference and a reference belonging elsewhere both fail here.
        // MATCH SIMPLE is what leaves an account without a product alone: the
        // pair is unchecked as soon as product_code is null.
        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD CONSTRAINT account_financial_accounts_product_fk FOREIGN KEY (product_code, kind)
                    REFERENCES catalog_products (code, account_kind) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE account_financial_accounts
                ADD CONSTRAINT account_financial_accounts_institution_present CHECK (
                    institution IS NULL
                    OR (btrim(institution) = institution AND institution <> '')
                )
            SQL);

        // PostgreSQL does not index a referencing foreign-key column on its own;
        // this keeps the ON DELETE RESTRICT check on catalog_products off a scan.
        $this->addSql('CREATE INDEX account_financial_accounts_product_index ON account_financial_accounts (product_code, kind)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX account_financial_accounts_product_index');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_institution_present');
        $this->addSql('ALTER TABLE account_financial_accounts DROP CONSTRAINT account_financial_accounts_product_fk');
        $this->addSql('ALTER TABLE catalog_products DROP CONSTRAINT catalog_products_code_kind_unique');
        $this->addSql('ALTER TABLE account_financial_accounts DROP COLUMN institution');
        $this->addSql('ALTER TABLE account_financial_accounts DROP COLUMN product_code');
    }
}

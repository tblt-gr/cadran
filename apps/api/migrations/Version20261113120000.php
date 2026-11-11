<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    private const array WORKSPACE_TABLES = [
        'portfolio_instruments', 'account_product_models', 'account_product_model_capabilities',
        'account_product_model_rules', 'account_product_model_rate_brackets', 'transaction_categorization_rules',
        'portfolio_operations', 'portfolio_operation_legs', 'portfolio_valuations', 'portfolio_position_snapshots',
    ];
    private const array CATALOG_TABLES = ['catalog_products', 'catalog_product_sources', 'catalog_product_rules', 'catalog_product_capabilities'];

    public function getDescription(): string
    {
        return 'Adds transactional revision guards for incremental numbered lists.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE foundation_list_revisions (workspace_id UUID PRIMARY KEY REFERENCES identity_workspaces(id) ON DELETE CASCADE, revision BIGINT NOT NULL DEFAULT 0 CHECK (revision >= 0))');
        $this->addSql('INSERT INTO foundation_list_revisions (workspace_id) SELECT id FROM identity_workspaces');
        $this->addSql('CREATE TABLE foundation_catalog_revision (singleton BOOLEAN PRIMARY KEY DEFAULT TRUE CHECK (singleton), revision BIGINT NOT NULL DEFAULT 0 CHECK (revision >= 0))');
        $this->addSql('INSERT INTO foundation_catalog_revision (singleton) VALUES (TRUE)');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION foundation_initialize_list_revision() RETURNS TRIGGER AS $$
            BEGIN
                INSERT INTO foundation_list_revisions (workspace_id) VALUES (NEW.id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER identity_workspace_list_revision AFTER INSERT ON identity_workspaces FOR EACH ROW EXECUTE FUNCTION foundation_initialize_list_revision()');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION foundation_advance_list_revision() RETURNS TRIGGER AS $$
            BEGIN
                UPDATE foundation_list_revisions SET revision = revision + 1
                    WHERE workspace_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.workspace_id ELSE NEW.workspace_id END;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        foreach (self::WORKSPACE_TABLES as $table) {
            $this->addSql('CREATE TRIGGER '.$table.'_list_revision AFTER INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION foundation_advance_list_revision()');
        }
        $this->addSql(<<<'SQL'
            CREATE FUNCTION foundation_advance_catalog_revision() RETURNS TRIGGER AS $$
            BEGIN
                UPDATE foundation_catalog_revision SET revision = revision + 1 WHERE singleton = TRUE;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        foreach (self::CATALOG_TABLES as $table) {
            $this->addSql('CREATE TRIGGER '.$table.'_list_revision AFTER INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION foundation_advance_catalog_revision()');
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([...self::WORKSPACE_TABLES, ...self::CATALOG_TABLES] as $table) {
            $this->addSql('DROP TRIGGER '.$table.'_list_revision ON '.$table);
        }
        $this->addSql('DROP TRIGGER identity_workspace_list_revision ON identity_workspaces');
        $this->addSql('DROP FUNCTION foundation_initialize_list_revision()');
        $this->addSql('DROP FUNCTION foundation_advance_list_revision()');
        $this->addSql('DROP FUNCTION foundation_advance_catalog_revision()');
        $this->addSql('DROP TABLE foundation_list_revisions');
        $this->addSql('DROP TABLE foundation_catalog_revision');
    }
}

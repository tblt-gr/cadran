<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates identity users, workspaces, memberships, and the one-time provisioning guard.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_users (
                id UUID NOT NULL,
                email VARCHAR(254) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX identity_users_email_unique ON identity_users (LOWER(email))');
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_workspaces (
                id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                base_currency CHAR(3) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT identity_workspaces_base_currency_format CHECK (base_currency ~ '^[A-Z]{3}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_workspace_memberships (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                user_id UUID NOT NULL,
                role VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT identity_workspace_memberships_workspace_fk
                    FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT identity_workspace_memberships_user_fk
                    FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT identity_workspace_memberships_role_valid CHECK (role IN ('OWNER')),
                CONSTRAINT identity_workspace_memberships_workspace_user_unique UNIQUE (workspace_id, user_id)
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX identity_workspace_memberships_one_owner_unique ON identity_workspace_memberships (workspace_id) WHERE role = 'OWNER'");
        // PostgreSQL does not index a referencing foreign-key column on its own;
        // this keeps ON DELETE RESTRICT checks and per-user lookups off a seq scan.
        $this->addSql('CREATE INDEX identity_workspace_memberships_user_idx ON identity_workspace_memberships (user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_initial_provisionings (
                id BOOLEAN NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT identity_initial_provisionings_singleton CHECK (id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_initial_provisionings');
        $this->addSql('DROP TABLE identity_workspace_memberships');
        $this->addSql('DROP TABLE identity_workspaces');
        $this->addSql('DROP TABLE identity_users');
    }
}

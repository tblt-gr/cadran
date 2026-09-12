<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stores workspace-scoped idempotency outcomes for money-movement creation requests.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_idempotency_keys (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                use_case VARCHAR(64) NOT NULL,
                idempotency_key VARCHAR(255) NOT NULL,
                request_fingerprint CHAR(64) NOT NULL,
                status VARCHAR(9) NOT NULL,
                response_status SMALLINT DEFAULT NULL,
                response_body JSONB DEFAULT NULL,
                entity_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                completed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT transaction_idempotency_keys_workspace_fk FOREIGN KEY (workspace_id)
                    REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT transaction_idempotency_keys_unique UNIQUE (workspace_id, use_case, idempotency_key),
                CONSTRAINT transaction_idempotency_keys_status_valid CHECK (status IN ('IN_FLIGHT', 'COMPLETED')),
                CONSTRAINT transaction_idempotency_keys_completed_shape CHECK (
                    (status = 'IN_FLIGHT' AND response_status IS NULL AND completed_at IS NULL)
                    OR (status = 'COMPLETED' AND response_status IS NOT NULL AND completed_at IS NOT NULL)
                ),
                CONSTRAINT transaction_idempotency_keys_fingerprint_valid CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
                CONSTRAINT transaction_idempotency_keys_key_valid CHECK (idempotency_key ~ '^[A-Za-z0-9_.:-]{16,255}$'),
                CONSTRAINT transaction_idempotency_keys_body_is_object CHECK (response_body IS NULL OR jsonb_typeof(response_body) = 'object'),
                CONSTRAINT transaction_idempotency_keys_body_bounded CHECK (response_body IS NULL OR length(response_body::text) <= 65536)
            )
            SQL);
        $this->addSql('CREATE INDEX transaction_idempotency_keys_expiry_index ON transaction_idempotency_keys (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transaction_idempotency_keys');
    }
}

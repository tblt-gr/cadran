<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the append-only, workspace-scoped audit event trail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_events (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                actor_id UUID DEFAULT NULL,
                event_type VARCHAR(64) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id UUID NOT NULL,
                before_json JSONB DEFAULT NULL,
                after_json JSONB DEFAULT NULL,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT audit_events_workspace_fk
                    FOREIGN KEY (workspace_id) REFERENCES identity_workspaces (id) ON DELETE RESTRICT,
                CONSTRAINT audit_events_actor_fk
                    FOREIGN KEY (actor_id) REFERENCES identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT audit_events_event_type_format CHECK (event_type ~ '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$'),
                CONSTRAINT audit_events_entity_type_format CHECK (entity_type ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT audit_events_before_json_is_object CHECK (before_json IS NULL OR jsonb_typeof(before_json) = 'object'),
                CONSTRAINT audit_events_after_json_is_object CHECK (after_json IS NULL OR jsonb_typeof(after_json) = 'object'),
                -- Backstop for a writer that bypasses the AuditDiff value
                -- object, which caps one encoded side at 4000 bytes. The gap
                -- absorbs the extra spacing jsonb adds when rendered as text.
                CONSTRAINT audit_events_diff_is_bounded CHECK (
                    length(coalesce(before_json::text, '')) <= 4096
                    AND length(coalesce(after_json::text, '')) <= 4096
                )
            )
            SQL);

        // The read path always filters on workspace_id and pages backwards on
        // (occurred_at, id); this index serves both without a sort.
        $this->addSql('CREATE INDEX audit_events_workspace_timeline_idx ON audit_events (workspace_id, occurred_at DESC, id DESC)');
        // PostgreSQL does not index a referencing foreign-key column on its own;
        // this keeps the ON DELETE RESTRICT check on identity_users off a seq scan.
        $this->addSql('CREATE INDEX audit_events_actor_idx ON audit_events (actor_id)');

        // Append-only at the row level: the application can only insert. This
        // stops an ordinary UPDATE or DELETE — including an accidental one from
        // a future use case — from rewriting history. TRUNCATE and direct
        // superuser access remain outside the database's reach and are covered
        // by the deployment posture of a self-hosted single-user install.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION audit_events_reject_rewrite() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only' USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_reject_rewrite()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER audit_events_append_only ON audit_events');
        $this->addSql('DROP FUNCTION audit_events_reject_rewrite()');
        $this->addSql('DROP TABLE audit_events');
    }
}

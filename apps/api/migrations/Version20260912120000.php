<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Moves session storage from native files to PostgreSQL so a credential change can revoke open sessions.';
    }

    public function up(Schema $schema): void
    {
        // Column names and types are those PdoSessionHandler writes for the
        // pgsql driver; the handler owns the contents, which are opaque
        // serialized session data and are never read by application SQL.
        // The table carries no workspace_id: a session precedes any workspace
        // and belongs to the runtime, like identity_users itself.
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL
            )
            SQL);

        // sess_lifetime holds an absolute expiry instant, and the handler's
        // garbage collection deletes on `sess_lifetime < now`; without the index
        // every sweep is a sequential scan of every open session.
        $this->addSql('CREATE INDEX sessions_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        // Rolling back signs everyone out: the previous storage was a file
        // directory this table replaced, and nothing copies the rows back.
        $this->addSql('DROP TABLE sessions');
    }
}

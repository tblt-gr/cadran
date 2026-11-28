<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128120000 extends AbstractMigration
{
    private const string REFRESH_NOTE = 'Mise à jour automatique après une opération.';
    private const string TAKEOVER_NOTE = 'Reprise des positions.';

    public function getDescription(): string
    {
        return '[data] Records the origin of automatic portfolio valuations as their source instead of a stored sentence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_valuations DROP CONSTRAINT portfolio_valuations_source_valid');
        $this->addSql("ALTER TABLE portfolio_valuations ADD CONSTRAINT portfolio_valuations_source_valid CHECK (source IN ('MANUAL', 'IMPORT', 'REFRESH', 'TAKEOVER'))");
        $this->addSql("UPDATE portfolio_valuations v SET source = 'TAKEOVER', note = NULL FROM portfolio_takeovers t WHERE t.valuation_id = v.id AND t.workspace_id = v.workspace_id");
        $this->addSql("UPDATE portfolio_valuations SET source = 'REFRESH', note = NULL WHERE note = ? AND source = 'MANUAL'", [self::REFRESH_NOTE]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_valuations DROP CONSTRAINT portfolio_valuations_source_valid');
        $this->addSql("UPDATE portfolio_valuations SET source = 'MANUAL', note = ? WHERE source = 'REFRESH'", [self::REFRESH_NOTE]);
        $this->addSql("UPDATE portfolio_valuations SET source = 'MANUAL', note = ? WHERE source = 'TAKEOVER'", [self::TAKEOVER_NOTE]);
        $this->addSql("ALTER TABLE portfolio_valuations ADD CONSTRAINT portfolio_valuations_source_valid CHECK (source IN ('MANUAL', 'IMPORT'))");
    }
}

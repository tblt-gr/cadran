<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the owner password hash and the account disable timestamp for same-origin session authentication.';
    }

    public function up(Schema $schema): void
    {
        // Both columns are nullable on purpose. IDN-001 provisions the owner
        // before any password exists; the first-run web flow sets password_hash
        // exactly once. disabled_at IS NULL means the account is active; a
        // timestamp records when it was disabled.
        $this->addSql('ALTER TABLE identity_users ADD password_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE identity_users ADD disabled_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_users DROP disabled_at');
        $this->addSql('ALTER TABLE identity_users DROP password_hash');
    }
}

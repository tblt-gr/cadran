<?php

declare(strict_types=1);

final class Version20260831000001
{
    public function up(): void
    {
        $this->addSql('ALTER TABLE later_scoped_records ADD workspace_id UUID NOT NULL');
        $this->addSql('ALTER TABLE renamed_column_records RENAME COLUMN tenant_id TO workspace_id');
        $this->addSql('ALTER TABLE old_scoped_records RENAME TO renamed_table_records');
    }
}

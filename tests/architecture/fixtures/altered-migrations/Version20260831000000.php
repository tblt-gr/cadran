<?php

declare(strict_types=1);

final class Version20260831000000
{
    public function up(): void
    {
        $this->addSql('CREATE TABLE later_scoped_records (id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE renamed_column_records (id UUID NOT NULL, tenant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE old_scoped_records (id UUID NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        // Ordinary multi-line DDL: the inline REFERENCES closes a parenthesis
        // at end of line before workspace_id is declared. A body read by lazy
        // regex stops there and never sees the column.
        $this->addSql(<<<'SQL'
            CREATE TABLE multiline_records (
                id UUID NOT NULL,
                owner_id UUID NOT NULL REFERENCES identity_users (id)
                    ON DELETE RESTRICT,
                balance NUMERIC(50, 24)
                    DEFAULT NULL,
                workspace_id UUID NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
    }
}

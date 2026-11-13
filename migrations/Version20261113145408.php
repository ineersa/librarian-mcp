<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113145408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE index_generations ADD COLUMN pending_parent CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__index_generations AS SELECT id, signature, source_identity, source_path, root_binding, active, state, source_cursor, scan_round, scan_revision, scan_witness, committed_revision, committed_source_witness, parent_count, chunk_count, started_at, completed_at, error_at, last_error, created_at, updated_at, project_id FROM index_generations');
        $this->addSql('DROP TABLE index_generations');
        $this->addSql('CREATE TABLE index_generations (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, signature VARCHAR(64) NOT NULL, source_identity VARCHAR(64) NOT NULL, source_path VARCHAR(2048) NOT NULL, root_binding VARCHAR(2048) NOT NULL, active BOOLEAN NOT NULL, state VARCHAR(20) NOT NULL, source_cursor CLOB DEFAULT NULL, scan_round INTEGER NOT NULL, scan_revision INTEGER DEFAULT NULL, scan_witness VARCHAR(64) DEFAULT NULL, committed_revision INTEGER DEFAULT NULL, committed_source_witness VARCHAR(64) DEFAULT NULL, parent_count INTEGER NOT NULL, chunk_count INTEGER NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, error_at DATETIME DEFAULT NULL, last_error CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_6D0A43BD166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO index_generations (id, signature, source_identity, source_path, root_binding, active, state, source_cursor, scan_round, scan_revision, scan_witness, committed_revision, committed_source_witness, parent_count, chunk_count, started_at, completed_at, error_at, last_error, created_at, updated_at, project_id) SELECT id, signature, source_identity, source_path, root_binding, active, state, source_cursor, scan_round, scan_revision, scan_witness, committed_revision, committed_source_witness, parent_count, chunk_count, started_at, completed_at, error_at, last_error, created_at, updated_at, project_id FROM __temp__index_generations');
        $this->addSql('DROP TABLE __temp__index_generations');
        $this->addSql('CREATE INDEX idx_index_generation_project_active ON index_generations (project_id, active)');
        $this->addSql('CREATE INDEX idx_index_generation_project_id ON index_generations (project_id, id)');
        $this->addSql('CREATE INDEX IDX_6D0A43BD166D1F9C ON index_generations (project_id)');
    }
}

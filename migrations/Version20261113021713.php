<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113021713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE index_generations (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, signature VARCHAR(64) NOT NULL, source_identity VARCHAR(64) NOT NULL, source_path VARCHAR(2048) NOT NULL, root_binding VARCHAR(2048) NOT NULL, active BOOLEAN NOT NULL, state VARCHAR(20) NOT NULL, source_cursor CLOB DEFAULT NULL, scan_round INTEGER NOT NULL, scan_revision INTEGER DEFAULT NULL, scan_witness VARCHAR(64) DEFAULT NULL, committed_revision INTEGER DEFAULT NULL, committed_source_witness VARCHAR(64) DEFAULT NULL, parent_count INTEGER NOT NULL, chunk_count INTEGER NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, error_at DATETIME DEFAULT NULL, last_error CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_6D0A43BD166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_index_generation_project_active ON index_generations (project_id, active)');
        $this->addSql('CREATE INDEX idx_index_generation_project_id ON index_generations (project_id, id)');
        $this->addSql('CREATE INDEX IDX_6D0A43BD166D1F9C ON index_generations (project_id)');
        $this->addSql('CREATE TABLE indexed_memories (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, kind VARCHAR(32) NOT NULL, source_id CLOB NOT NULL, session_id CLOB NOT NULL, occurred_at INTEGER NOT NULL, content_signature VARCHAR(64) NOT NULL, chunk_ids CLOB NOT NULL, last_seen_round INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, generation_id INTEGER NOT NULL, CONSTRAINT FK_3D9D16D4553A6EC4 FOREIGN KEY (generation_id) REFERENCES index_generations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_indexed_memory_generation_round ON indexed_memories (generation_id, last_seen_round)');
        $this->addSql('CREATE UNIQUE INDEX uniq_indexed_memory_generation_kind_source ON indexed_memories (generation_id, kind, source_id)');
        $this->addSql('CREATE INDEX IDX_3D9D16D4553A6EC4 ON indexed_memories (generation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE index_generations');
        $this->addSql('DROP TABLE indexed_memories');
    }
}

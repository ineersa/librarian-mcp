<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112211938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE projects (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              name_normalized VARCHAR(255) NOT NULL,
              root_path VARCHAR(2048) NOT NULL,
              root_canonical VARCHAR(2048) NOT NULL,
              repository_origin VARCHAR(2048) DEFAULT NULL,
              health VARCHAR(20) NOT NULL,
              last_error CLOB DEFAULT NULL,
              last_scan_at DATETIME DEFAULT NULL,
              last_successful_scan_at DATETIME DEFAULT NULL,
              last_successful_index_at DATETIME DEFAULT NULL,
              database_path VARCHAR(2048) DEFAULT NULL,
              sessions_path VARCHAR(2048) DEFAULT NULL,
              schema_version VARCHAR(255) DEFAULT NULL,
              observation_count INTEGER NOT NULL,
              reflection_count INTEGER NOT NULL,
              session_count INTEGER NOT NULL,
              source_revision INTEGER DEFAULT NULL,
              source_change_count INTEGER NOT NULL,
              source_details CLOB NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_project_name_normalized ON projects (name_normalized)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_root_canonical ON projects (root_canonical)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_repository_origin ON projects (repository_origin)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE projects');
    }
}

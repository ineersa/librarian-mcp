<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428015040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE libraries (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, git_url VARCHAR(255) NOT NULL, branch VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, description CLOB NOT NULL, status VARCHAR(20) NOT NULL, vera_config CLOB DEFAULT NULL, last_error CLOB DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, last_indexed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_slug ON libraries (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_path ON libraries (path)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE libraries');
    }
}

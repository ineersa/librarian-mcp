<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428211726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE libraries ADD COLUMN readable_files CLOB DEFAULT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, roles, password, created_at, updated_at FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, mcp_token_hash VARCHAR(64) DEFAULT NULL, mcp_token_created_at DATETIME DEFAULT NULL, mcp_token_last_used_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO users (id, email, roles, password, created_at, updated_at) SELECT id, email, roles, password, created_at, updated_at FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('UPDATE users SET roles = REPLACE(roles, \'"ROLE_ADMIN"\', \'"ROLE_ADMIN","ROLE_MCP"\') WHERE roles LIKE \'%ROLE_ADMIN%\' AND roles NOT LIKE \'%ROLE_MCP%\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9C69FA79F ON users (mcp_token_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__libraries AS SELECT id, name, slug, git_url, branch, path, description, status, vera_config, last_error, last_synced_at, last_indexed_at, created_at, updated_at FROM libraries');
        $this->addSql('DROP TABLE libraries');
        $this->addSql('CREATE TABLE libraries (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, git_url VARCHAR(255) NOT NULL, branch VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, description CLOB NOT NULL, status VARCHAR(20) NOT NULL, vera_config CLOB DEFAULT NULL, last_error CLOB DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, last_indexed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO libraries (id, name, slug, git_url, branch, path, description, status, vera_config, last_error, last_synced_at, last_indexed_at, created_at, updated_at) SELECT id, name, slug, git_url, branch, path, description, status, vera_config, last_error, last_synced_at, last_indexed_at, created_at, updated_at FROM __temp__libraries');
        $this->addSql('DROP TABLE __temp__libraries');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_slug ON libraries (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_path ON libraries (path)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, roles, password, created_at, updated_at FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO users (id, email, roles, password, created_at, updated_at) SELECT id, email, roles, password, created_at, updated_at FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON users (email)');
    }
}

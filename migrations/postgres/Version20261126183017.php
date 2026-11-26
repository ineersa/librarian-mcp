<?php

declare(strict_types=1);

namespace DoctrineMigrations\Postgres;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261126183017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE librarian_settings ADD embedding_mode VARCHAR(16) DEFAULT \'api\' NOT NULL');
        $this->addSql('ALTER TABLE librarian_settings ADD embedding_api_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE librarian_settings ADD embedding_api_model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE librarian_settings ADD reranker_mode VARCHAR(16) DEFAULT \'api\' NOT NULL');
        $this->addSql('ALTER TABLE librarian_settings ADD reranker_api_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE librarian_settings ADD reranker_api_model VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE librarian_settings DROP embedding_mode');
        $this->addSql('ALTER TABLE librarian_settings DROP embedding_api_url');
        $this->addSql('ALTER TABLE librarian_settings DROP embedding_api_model');
        $this->addSql('ALTER TABLE librarian_settings DROP reranker_mode');
        $this->addSql('ALTER TABLE librarian_settings DROP reranker_api_url');
        $this->addSql('ALTER TABLE librarian_settings DROP reranker_api_model');
    }
}

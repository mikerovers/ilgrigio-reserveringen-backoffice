<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add predefined editable website pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_page (
            id UUID NOT NULL,
            page_key VARCHAR(100) NOT NULL,
            hero_title VARCHAR(255) NOT NULL,
            hero_date_text VARCHAR(255) NOT NULL,
            hero_description TEXT NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_page_key ON site_page (page_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE site_page');
    }
}

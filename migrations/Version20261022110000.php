<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

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
        $this->addSql(<<<'SQL'
            INSERT INTO site_page (id, page_key, hero_title, hero_date_text, hero_description)
            VALUES (
                ?,
                'homepage',
                'Biblio Magico!',
                '31 juli t/m zondag 15 augustus 2027',
                'Dit jaar presenteerden wij onze show Biblio Magico! De Bibliothecaresse en haar assistent probeerden '
                || 'uit alle macht hun bibliotheek netjes op orde te houden. Dat bleek niet makkelijk met een Magisch '
                || 'Boek dat verhalen tot leven bracht.'
            )
            SQL, [Uuid::v4()->toRfc4122()]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE site_page');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    private const TERMS_TEXT = 'Jeugdcircus Il Grigio gebruikt Mollie voor de verwerking van alle online transacties. '
        . 'Uw persoonsgegevens worden in dit kader gedeeld met Mollie en aan haar gelieerde ondernemingen. '
        . 'Wanneer u via onze website uw kaarten reserveert, dan geeft u, direct of indirect, toestemming '
        . 'aan Jeugdcircus Il Grigio, Mollie en aan haar gelieerde ondernemingen, alle vereiste '
        . '(uitdrukkelijke) toestemmingen als bedoeld in de toepasselijke gegevensbeschermingswetgeving te verwerken.';

    public function getDescription(): string
    {
        return 'Seed the editable terms & conditions website page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            "INSERT INTO site_page (id, page_key, hero_title, hero_date_text, hero_description) "
            . "SELECT gen_random_uuid(), 'terms', 'Algemene Voorwaarden', '', '%s' "
            . "WHERE NOT EXISTS (SELECT 1 FROM site_page WHERE page_key = 'terms')",
            self::TERMS_TEXT,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM site_page WHERE page_key = 'terms'");
    }
}

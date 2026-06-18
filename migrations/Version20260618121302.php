<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618121302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused TicketType columns: age_range, requirements, regular_price_cents, sale_price_cents, sku, image_url';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_ticket_type_sku');
        $this->addSql('ALTER TABLE ticket_type DROP age_range');
        $this->addSql('ALTER TABLE ticket_type DROP requirements');
        $this->addSql('ALTER TABLE ticket_type DROP regular_price_cents');
        $this->addSql('ALTER TABLE ticket_type DROP sale_price_cents');
        $this->addSql('ALTER TABLE ticket_type DROP sku');
        $this->addSql('ALTER TABLE ticket_type DROP image_url');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_type ADD age_range VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD requirements JSON NOT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD regular_price_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD sale_price_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD sku VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD image_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_ticket_type_sku ON ticket_type (sku)');
    }
}

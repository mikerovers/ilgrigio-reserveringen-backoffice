<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618141309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove stock fields from event (managed via SharedStock)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event DROP stock_quantity');
        $this->addSql('ALTER TABLE event DROP stock_status');
        $this->addSql('ALTER TABLE event DROP low_stock_amount');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event ADD stock_quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD stock_status VARCHAR(255) DEFAULT \'instock\' NOT NULL');
        $this->addSql('ALTER TABLE event ADD low_stock_amount INT DEFAULT NULL');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618142953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add disabled and disabled_reason to ticket_type; remove standalone stock columns (managed via SharedStock)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_type ADD disabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD disabled_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_type DROP manage_stock');
        $this->addSql('ALTER TABLE ticket_type DROP stock_quantity');
        $this->addSql('ALTER TABLE ticket_type DROP stock_status');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_type ADD manage_stock BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD stock_quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_type ADD stock_status VARCHAR(255) DEFAULT \'instock\' NOT NULL');
        $this->addSql('ALTER TABLE ticket_type DROP disabled');
        $this->addSql('ALTER TABLE ticket_type DROP disabled_reason');
    }
}

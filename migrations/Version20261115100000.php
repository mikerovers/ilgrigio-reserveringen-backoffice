<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow shared stock pools to go below zero so the register can overbook (rebooking orders)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_stock DROP CONSTRAINT chk_shared_stock_non_negative');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE shared_stock SET stock_quantity = 0 WHERE stock_quantity < 0');
        $this->addSql('ALTER TABLE shared_stock ADD CONSTRAINT chk_shared_stock_non_negative CHECK (stock_quantity IS NULL OR stock_quantity >= 0)');
    }
}

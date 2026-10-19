<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared stock low stock threshold';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_stock ADD low_stock_threshold INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_stock DROP low_stock_threshold');
    }
}

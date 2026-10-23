<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023160228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event doors-open time and its ticket snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD doors_open_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD event_doors_open_snapshot TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP doors_open_at');
        $this->addSql('ALTER TABLE ticket DROP event_doors_open_snapshot');
    }
}

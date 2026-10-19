<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019220832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event display stock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD display_stock_id UUID DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA76A27C43E FOREIGN KEY (display_stock_id) '
            . 'REFERENCES shared_stock (id) ON DELETE SET NULL NOT DEFERRABLE'
        );
        $this->addSql('CREATE INDEX IDX_3BAE0AA76A27C43E ON event (display_stock_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP CONSTRAINT FK_3BAE0AA76A27C43E');
        $this->addSql('DROP INDEX IDX_3BAE0AA76A27C43E');
        $this->addSql('ALTER TABLE event DROP display_stock_id');
    }
}

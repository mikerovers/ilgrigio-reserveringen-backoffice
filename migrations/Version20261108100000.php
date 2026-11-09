<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add barcode scanner types and their Code 128 programming barcodes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE barcode_scanner_type (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, manufacturer VARCHAR(255) DEFAULT NULL, notes TEXT DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_barcode_scanner_type_slug ON barcode_scanner_type (slug)');

        $this->addSql('CREATE TABLE scanner_programming_barcode (id UUID NOT NULL, scanner_type_id UUID NOT NULL, label VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, payload TEXT NOT NULL, code_set VARCHAR(255) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_scanner_barcode_type ON scanner_programming_barcode (scanner_type_id)');
        $this->addSql('ALTER TABLE scanner_programming_barcode ADD CONSTRAINT FK_1D608F711D578ED3 FOREIGN KEY (scanner_type_id) REFERENCES barcode_scanner_type (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scanner_programming_barcode DROP CONSTRAINT FK_1D608F711D578ED3');
        $this->addSql('DROP TABLE scanner_programming_barcode');
        $this->addSql('DROP TABLE barcode_scanner_type');
    }
}

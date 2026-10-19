<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register orders: source, idempotency key and manual discount audit columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_order ADD manual_discount_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_order ADD manual_discount_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_order ADD discount_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_order ADD source VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_order ADD idempotency_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_idempotency_key ON customer_order (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_order_idempotency_key');
        $this->addSql('ALTER TABLE customer_order DROP idempotency_key');
        $this->addSql('ALTER TABLE customer_order DROP source');
        $this->addSql('ALTER TABLE customer_order DROP discount_reason');
        $this->addSql('ALTER TABLE customer_order DROP manual_discount_amount');
        $this->addSql('ALTER TABLE customer_order DROP manual_discount_type');
    }
}

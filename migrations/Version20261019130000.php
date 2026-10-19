<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the shipping address snapshot from orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_order DROP shipping_first_name, DROP shipping_last_name, DROP shipping_company_name, DROP shipping_city, DROP shipping_phone_number, DROP shipping_email');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customer_order ADD shipping_first_name VARCHAR(255) NOT NULL DEFAULT '', ADD shipping_last_name VARCHAR(255) NOT NULL DEFAULT '', ADD shipping_company_name VARCHAR(255) DEFAULT NULL, ADD shipping_city VARCHAR(255) NOT NULL DEFAULT '', ADD shipping_phone_number VARCHAR(50) DEFAULT NULL, ADD shipping_email VARCHAR(255) DEFAULT NULL");
        $this->addSql("UPDATE customer_order SET shipping_first_name = billing_first_name, shipping_last_name = billing_last_name, shipping_company_name = billing_company_name, shipping_city = billing_city");
        $this->addSql('ALTER TABLE customer_order ALTER shipping_first_name DROP DEFAULT, ALTER shipping_last_name DROP DEFAULT, ALTER shipping_city DROP DEFAULT');
    }
}

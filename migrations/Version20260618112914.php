<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618112914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE coupon (id UUID NOT NULL, code VARCHAR(64) NOT NULL, discount_type VARCHAR(255) NOT NULL, amount INT NOT NULL, description TEXT DEFAULT NULL, active BOOLEAN DEFAULT true NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_coupon_code ON coupon (code)');
        $this->addSql('CREATE TABLE customer (id UUID NOT NULL, email VARCHAR(255) NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, company_name VARCHAR(255) DEFAULT NULL, city VARCHAR(255) DEFAULT NULL, phone_number VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_email ON customer (email)');
        $this->addSql('CREATE TABLE customer_order (id UUID NOT NULL, order_number VARCHAR(32) NOT NULL, status VARCHAR(255) NOT NULL, currency VARCHAR(3) DEFAULT \'EUR\' NOT NULL, subtotal_net_cents INT NOT NULL, tax_cents INT NOT NULL, discount_gross_cents INT NOT NULL, discount_tax_cents INT NOT NULL, total_gross_cents INT NOT NULL, tax_rate_bp INT DEFAULT 900 NOT NULL, tax_label VARCHAR(32) DEFAULT \'BTW\' NOT NULL, tax_rate_code VARCHAR(32) DEFAULT NULL, coupon_code VARCHAR(64) DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, billing_first_name VARCHAR(255) NOT NULL, billing_last_name VARCHAR(255) NOT NULL, billing_company_name VARCHAR(255) DEFAULT NULL, billing_city VARCHAR(255) NOT NULL, billing_phone_number VARCHAR(50) DEFAULT NULL, billing_email VARCHAR(255) DEFAULT NULL, shipping_first_name VARCHAR(255) NOT NULL, shipping_last_name VARCHAR(255) NOT NULL, shipping_company_name VARCHAR(255) DEFAULT NULL, shipping_city VARCHAR(255) NOT NULL, shipping_phone_number VARCHAR(50) DEFAULT NULL, shipping_email VARCHAR(255) DEFAULT NULL, customer_id UUID NOT NULL, coupon_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3B1CE6A366C5951B ON customer_order (coupon_id)');
        $this->addSql('CREATE INDEX idx_order_status ON customer_order (status)');
        $this->addSql('CREATE INDEX idx_order_customer ON customer_order (customer_id)');
        $this->addSql('CREATE INDEX idx_order_created ON customer_order (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_number ON customer_order (order_number)');
        $this->addSql('CREATE TABLE event (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, slug VARCHAR(255) NOT NULL, image_url VARCHAR(1024) DEFAULT NULL, permalink VARCHAR(1024) DEFAULT NULL, stock_quantity INT DEFAULT NULL, stock_status VARCHAR(255) DEFAULT \'instock\' NOT NULL, low_stock_amount INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_event_starts_at ON event (starts_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_slug ON event (slug)');
        $this->addSql('CREATE TABLE event_event_category (event_id UUID NOT NULL, event_category_id UUID NOT NULL, PRIMARY KEY (event_id, event_category_id))');
        $this->addSql('CREATE INDEX IDX_9FE4466271F7E88B ON event_event_category (event_id)');
        $this->addSql('CREATE INDEX IDX_9FE44662B9CF4E62 ON event_event_category (event_category_id)');
        $this->addSql('CREATE TABLE event_category (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_category_slug ON event_category (slug)');
        $this->addSql('CREATE TABLE order_line_item (id UUID NOT NULL, name VARCHAR(255) NOT NULL, quantity INT DEFAULT 1 NOT NULL, unit_net_cents INT NOT NULL, unit_gross_cents INT NOT NULL, line_net_cents INT NOT NULL, line_tax_cents INT NOT NULL, line_gross_cents INT NOT NULL, order_id UUID NOT NULL, ticket_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_line_order ON order_line_item (order_id)');
        $this->addSql('CREATE INDEX idx_line_ticket_type ON order_line_item (ticket_type_id)');
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, mollie_payment_id VARCHAR(64) DEFAULT NULL, status VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) DEFAULT \'EUR\' NOT NULL, method VARCHAR(64) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840D8D9F6D38 ON payment (order_id)');
        $this->addSql('CREATE INDEX idx_payment_status ON payment (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_mollie_id ON payment (mollie_payment_id)');
        $this->addSql('CREATE TABLE ticket (id UUID NOT NULL, ticket_code VARCHAR(64) NOT NULL, ticket_name VARCHAR(255) NOT NULL, event_name_snapshot VARCHAR(255) NOT NULL, event_datetime_snapshot TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, checked_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, checked_in_by VARCHAR(255) DEFAULT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_id UUID NOT NULL, line_item_id UUID NOT NULL, ticket_type_id UUID NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_97A0ADA3A7CBD339 ON ticket (line_item_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3C980D5C1 ON ticket (ticket_type_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA371F7E88B ON ticket (event_id)');
        $this->addSql('CREATE INDEX idx_ticket_event_code ON ticket (event_id, ticket_code)');
        $this->addSql('CREATE INDEX idx_ticket_order ON ticket (order_id)');
        $this->addSql('CREATE INDEX idx_ticket_status ON ticket (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ticket_code ON ticket (ticket_code)');
        $this->addSql('CREATE TABLE ticket_type (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, age_range VARCHAR(50) DEFAULT NULL, requirements JSON NOT NULL, price_cents INT NOT NULL, regular_price_cents INT DEFAULT NULL, sale_price_cents INT DEFAULT NULL, sku VARCHAR(100) DEFAULT NULL, manage_stock BOOLEAN NOT NULL, stock_quantity INT DEFAULT NULL, stock_status VARCHAR(255) DEFAULT \'instock\' NOT NULL, image_url VARCHAR(1024) DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ticket_type_event ON ticket_type (event_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ticket_type_sku ON ticket_type (sku)');
        $this->addSql('ALTER TABLE customer_order ADD CONSTRAINT FK_3B1CE6A39395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE customer_order ADD CONSTRAINT FK_3B1CE6A366C5951B FOREIGN KEY (coupon_id) REFERENCES coupon (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_event_category ADD CONSTRAINT FK_9FE4466271F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_event_category ADD CONSTRAINT FK_9FE44662B9CF4E62 FOREIGN KEY (event_category_id) REFERENCES event_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_line_item ADD CONSTRAINT FK_28D1AD3F8D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_line_item ADD CONSTRAINT FK_28D1AD3FC980D5C1 FOREIGN KEY (ticket_type_id) REFERENCES ticket_type (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA38D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3A7CBD339 FOREIGN KEY (line_item_id) REFERENCES order_line_item (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3C980D5C1 FOREIGN KEY (ticket_type_id) REFERENCES ticket_type (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA371F7E88B FOREIGN KEY (event_id) REFERENCES event (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_type ADD CONSTRAINT FK_BE05421171F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_order DROP CONSTRAINT FK_3B1CE6A39395C3F3');
        $this->addSql('ALTER TABLE customer_order DROP CONSTRAINT FK_3B1CE6A366C5951B');
        $this->addSql('ALTER TABLE event_event_category DROP CONSTRAINT FK_9FE4466271F7E88B');
        $this->addSql('ALTER TABLE event_event_category DROP CONSTRAINT FK_9FE44662B9CF4E62');
        $this->addSql('ALTER TABLE order_line_item DROP CONSTRAINT FK_28D1AD3F8D9F6D38');
        $this->addSql('ALTER TABLE order_line_item DROP CONSTRAINT FK_28D1AD3FC980D5C1');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D8D9F6D38');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA38D9F6D38');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA3A7CBD339');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA3C980D5C1');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA371F7E88B');
        $this->addSql('ALTER TABLE ticket_type DROP CONSTRAINT FK_BE05421171F7E88B');
        $this->addSql('DROP TABLE coupon');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE customer_order');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE event_event_category');
        $this->addSql('DROP TABLE event_category');
        $this->addSql('DROP TABLE order_line_item');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE ticket_type');
    }
}

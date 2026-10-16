<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015064312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock reservations, unique ticket line index, non-negative stock constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_reservation (id UUID NOT NULL, quantity INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, order_id UUID NOT NULL, shared_stock_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9D06EF6158E99033 ON stock_reservation (shared_stock_id)');
        $this->addSql('CREATE INDEX idx_stock_reservation_order ON stock_reservation (order_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_reservation_order_pool ON stock_reservation (order_id, shared_stock_id)');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT FK_9D06EF618D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT FK_9D06EF6158E99033 FOREIGN KEY (shared_stock_id) REFERENCES shared_stock (id) ON DELETE RESTRICT NOT DEFERRABLE');
        // Existing tickets all get index 0; number them per line item before the unique index exists.
        $this->addSql('ALTER TABLE ticket ADD line_index INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE ticket t SET line_index = n.idx FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY line_item_id ORDER BY id) - 1 AS idx FROM ticket) n WHERE t.id = n.id');
        $this->addSql('CREATE UNIQUE INDEX uniq_ticket_line_index ON ticket (line_item_id, line_index)');

        // Stock already taken by unpaid orders: record it so app:orders:expire-pending can return it.
        $this->addSql("INSERT INTO stock_reservation (id, order_id, shared_stock_id, quantity, created_at) SELECT gen_random_uuid(), o.id, tt.shared_stock_id, SUM(li.quantity), o.created_at FROM customer_order o JOIN order_line_item li ON li.order_id = o.id JOIN ticket_type tt ON tt.id = li.ticket_type_id JOIN shared_stock ss ON ss.id = tt.shared_stock_id WHERE o.status = 'pending' AND ss.stock_quantity IS NOT NULL GROUP BY o.id, tt.shared_stock_id, o.created_at");

        // Pools are never allowed to go negative; deleting a pool must not silently make ticket types unlimited.
        $this->addSql('UPDATE shared_stock SET stock_quantity = 0 WHERE stock_quantity < 0');
        $this->addSql('ALTER TABLE shared_stock ADD CONSTRAINT chk_shared_stock_non_negative CHECK (stock_quantity IS NULL OR stock_quantity >= 0)');
        $this->addSql('ALTER TABLE ticket_type DROP CONSTRAINT FK_BE05421158E99033');
        $this->addSql('ALTER TABLE ticket_type ADD CONSTRAINT FK_BE05421158E99033 FOREIGN KEY (shared_stock_id) REFERENCES shared_stock (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_type DROP CONSTRAINT FK_BE05421158E99033');
        $this->addSql('ALTER TABLE ticket_type ADD CONSTRAINT FK_BE05421158E99033 FOREIGN KEY (shared_stock_id) REFERENCES shared_stock (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shared_stock DROP CONSTRAINT chk_shared_stock_non_negative');
        $this->addSql('ALTER TABLE stock_reservation DROP CONSTRAINT FK_9D06EF618D9F6D38');
        $this->addSql('ALTER TABLE stock_reservation DROP CONSTRAINT FK_9D06EF6158E99033');
        $this->addSql('DROP TABLE stock_reservation');
        $this->addSql('DROP INDEX uniq_ticket_line_index');
        $this->addSql('ALTER TABLE ticket DROP line_index');
    }
}

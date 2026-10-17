<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coupon usage limit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coupon ADD usage_limit INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coupon DROP usage_limit');
    }
}

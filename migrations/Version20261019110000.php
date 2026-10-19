<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer email is optional (register sales may be anonymous)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ALTER email DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE customer SET email = 'no-email+' || id || '@invalid.local' WHERE email IS NULL");
        $this->addSql('ALTER TABLE customer ALTER email SET NOT NULL');
    }
}

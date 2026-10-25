<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add login_code table for passwordless customer login';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE login_code (id UUID NOT NULL, email VARCHAR(255) NOT NULL, code_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, attempts SMALLINT NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_login_code_email_created ON login_code (email, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_code');
    }
}
